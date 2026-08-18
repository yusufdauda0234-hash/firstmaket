<?php

namespace App\Modules\Cart\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Cart\Services\CartCheckoutService;
use App\Modules\Cart\Services\CartService;
use App\Modules\Cart\Services\CartSummary;
use App\Modules\Catalog\Models\Product;
use App\Modules\Customer\Models\CustomerProfile;
use App\Modules\Orders\Services\PayOnDeliveryPolicy;
use App\Modules\Orders\Services\PromoRedeemer;
use App\Modules\Payments\Actions\StartPaystackPaymentAction;
use App\Modules\Savings\Models\PlanTerm;
use App\Modules\Savings\Services\SavingsGoalService;
use App\Modules\Savings\Services\SavingsService;
use App\Shared\Enums\CheckoutMethod;
use App\Shared\Enums\ProductStatus;
use App\Modules\Settings\Models\Country;
use App\Shared\Nigeria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Cart CRUD + checkout (docs/FirstMaket_Implementation_Plan.md Sprint 8).
 *
 * Adding to and editing a cart is open to guests — CartService keeps theirs
 * against a cookie and merges it on login. Checkout is not: it needs a user
 * to hang the savings debit and the orders off, so those routes stay behind
 * auth and the cart page sends guests to the sign-in modal instead.
 *
 * Checkout settles one of two ways: spend savings now, or save towards it —
 * which charges nothing and creates a savings goal at today's prices.
 */
class CartController extends Controller
{
    public function index(Request $request, CartService $cartService): Response
    {
        $lines = $cartService->lines($request->user());

        // Priced against the saved delivery state, exactly as checkout does.
        // Quoting the national default here and the real rate one page later
        // meant a Gombe shopper saw the fee change between cart and checkout.
        $state = $request->user()?->customerProfile?->default_state;

        return Inertia::render('Cart/Index', [
            'items' => $this->presentLines($lines),
            'summary' => CartSummary::fromLines($lines, $state)->toArray(),
            'recommendations' => $this->recommendations($lines),
            // Nobody has told us where this is going yet, so the figure is a
            // national default that checkout may revise. Big marketplaces say
            // "calculated at checkout" rather than quote a number they will
            // change — so the page is told which of the two it is showing.
            'deliveryIsEstimate' => $state === null,
        ]);
    }

    /**
     * "You may also like" — approved, in-stock listings from the categories
     * already in the cart, with the cart's own items excluded so nothing is
     * suggested back to someone who has already picked it.
     *
     * Falls back to newest approved stock for an empty or unusual cart, so
     * the rail is never a blank space on the page.
     *
     * @param  Collection<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function recommendations(Collection $lines): array
    {
        $inCart = $lines->map(fn (array $line) => $line['product']->id)->all();
        $categoryIds = $lines->map(fn (array $line) => $line['product']->category_id)->unique()->all();

        $query = Product::query()
            ->where('status', ProductStatus::Approved)
            ->where('stock_quantity', '>', 0)
            ->whereNotIn('id', $inCart)
            ->with(['category:id,name', 'vendor:id,business_name', 'images']);

        $products = $categoryIds === []
            ? collect()
            : (clone $query)->whereIn('category_id', $categoryIds)->latest('id')->take(6)->get();

        if ($products->count() < 6) {
            $products = $products->concat(
                (clone $query)
                    ->whereNotIn('id', $products->pluck('id')->all())
                    ->latest('id')
                    ->take(6 - $products->count())
                    ->get(),
            );
        }

        return $products->map(fn (Product $product) => [
            'uuid' => $product->uuid,
            'name' => $product->name,
            'slug' => $product->slug,
            'image' => $product->primaryImageUrl(),
            'priceKobo' => $product->price_kobo,
            'categoryName' => $product->category?->name,
            'vendorName' => $product->vendor?->business_name,
        ])->values()->all();
    }

    public function store(Request $request, CartService $cartService): RedirectResponse
    {
        $validated = $request->validate([
            'product_uuid' => ['required', 'string'],
            'quantity' => ['integer', 'min:1'],
        ]);

        $product = Product::query()->where('uuid', $validated['product_uuid'])->firstOrFail();

        $cartService->addItem($request->user(), $product, (int) ($validated['quantity'] ?? 1));

        // Deliberately no flash message. The storefront confirms this itself
        // (resources/js/Hooks/useAddToCart.ts) with a toast that names the
        // product and re-fires when the same item is added twice, which a
        // flash cannot do. Flashing here as well produced two stacked toasts
        // for one click.
        return back();
    }

    public function update(Request $request, Product $product, CartService $cartService): RedirectResponse
    {
        $validated = $request->validate(['quantity' => ['required', 'integer', 'min:1']]);

        $cartService->setQuantity($request->user(), $product, (int) $validated['quantity']);

        // Also silent: the quantity stepper and the line total visibly update,
        // so a toast on every increment is noise.
        return back();
    }

    public function destroy(Request $request, Product $product, CartService $cartService): RedirectResponse
    {
        $cartService->removeItem($request->user(), $product);

        return back()->with('success', 'Removed from cart.');
    }

    /** Checkout screen: whole cart, address and payment method here. */
    public function checkout(Request $request, CartService $cartService, SavingsService $savings): Response|RedirectResponse
    {
        $buyNow = $this->buyNowRequest($request);

        $lines = $buyNow
            ? $cartService->buyNowLines($buyNow['product'], $buyNow['quantity'])
            : $cartService->lines($request->user());

        if ($lines->isEmpty()) {
            return redirect()->route('cart.index');
        }

        $user = $request->user();

        // Priced against the saved delivery state where there is one, so the
        // fee shown is the fee charged rather than the national default.
        $summary = CartSummary::fromLines($lines, $user->customerProfile?->default_state);

        // Goods plus delivery, matching what createFromLines will actually
        // lock. A plan used to be quoted on the goods alone and fulfilled
        // with no delivery at all, so the same basket cost less paid over six
        // months than paid outright — backwards, and against the rule that
        // nothing is free unless it is set so on the delivery-rates page.
        $planTargetKobo = $summary->totalKobo;

        // Only terms the business currently offers, and only those whose
        // minimum this cart clears.
        $terms = PlanTerm::query()
            ->where('is_active', true)
            ->where('min_target_kobo', '<=', $planTargetKobo)
            ->orderBy('sort_order')
            ->orderBy('installments')
            ->get()
            ->map(fn (PlanTerm $term) => [
                'id' => $term->id,
                'name' => $term->name,
                'cadence' => $term->cadence->value,
                'cadenceLabel' => $term->cadence->label(),
                'installments' => $term->installments,
                'installmentKobo' => $term->installmentKoboFor($planTargetKobo),
                'durationLabel' => $term->durationLabel(),
                // Drives whether checkout asks for money now or just starts
                // the plan, and what the button says.
                'paysUpfront' => $term->paysUpfront(),
                'firstPaymentDueDays' => $term->first_payment_due_days,
                'firstPaymentLabel' => $term->firstPaymentLabel(),
            ]);

        return Inertia::render('Cart/Checkout', [
            'items' => $this->presentLines($lines),
            'summary' => $summary->toArray(),
            // Re-quoted here, so a code that stopped applying while the
            // shopper was editing the cart disappears rather than promising
            // a discount the charge will not honour.
            'promo' => $this->appliedPromo($request, $lines, $summary),
            // Echoed back so the form posts the same single item it displayed
            // rather than silently falling through to the whole cart.
            'buyNow' => $buyNow
                ? ['productUuid' => $buyNow['product']->uuid, 'quantity' => $buyNow['quantity']]
                : null,
            'planTerms' => $terms,
            // What a plan would actually lock — the goods, without delivery.
            'planTargetKobo' => $planTargetKobo,
            'planCreditKobo' => $savings->creditKobo($user),
            'contact' => ['name' => $user->name, 'phone' => $user->phone],
            // Prefills the form so a returning customer confirms an address
            // rather than retyping it.
            'savedAddress' => $user->customerProfile?->defaultAddress(),
            'countries' => Country::active()->map(fn (Country $c) => ['name' => $c->name, 'id' => $c->id])->all(),
            'statesByCountry' => Country::active()->mapWithKeys(function (Country $c) {
                $states = $c->states()->active()->pluck('name')->all();
                return [$c->id => $states];
            })->all(),
            'states' => Nigeria::STATES,
            'paymentMethods' => array_map(function (CheckoutMethod $method) use ($user, $summary) {
                // Pay on delivery is judged against this basket, not in the
                // abstract: the cap, the state and the customer own history
                // all decide it, and "unavailable" with no reason reads as a
                // broken site rather than a rule.
                $podReason = $method === CheckoutMethod::PayOnDelivery
                    ? PayOnDeliveryPolicy::refusalReason($user, $summary->subtotalKobo, $user->customerProfile?->default_state)
                    : null;

                return [
                    'value' => $method->value,
                    'label' => $method->label(),
                    'description' => $method->description(),
                    'available' => $method->isAvailable() && $podReason === null,
                    'unavailableReason' => $podReason ?? $method->unavailableReason(),
                ];
            }, CheckoutMethod::cases()),
        ]);
    }

    public function checkoutStore(
        Request $request,
        CartService $cartService,
        CartCheckoutService $checkoutService,
        SavingsGoalService $goalService,
        StartPaystackPaymentAction $startPayment,
    ): SymfonyResponse {
        $validated = $request->validate([
            'country_id' => ['required', 'integer', Rule::exists('countries', 'id')->where('is_active', true)],
            'recipient_name' => ['required', 'string', 'max:120'],
            // Nigerian mobile numbers, local or +234 form. Couriers call
            // ahead, so a reachable number is not optional.
            'recipient_phone' => ['required', 'string', 'regex:/^(\+?234|0)[789][01]\d{8}$/'],
            'delivery_address' => ['required', 'string', 'max:500'],
            'state' => ['required', 'string', 'max:80'],
            'lga' => ['required', 'string', 'max:80'],
            'landmark' => ['nullable', 'string', 'max:160'],
            'payment_method' => ['required', Rule::enum(CheckoutMethod::class)],
            // Required only for Pay Small Small — validated conditionally so
            // a card checkout is not asked for a term it never chose.
            'plan_term_id' => [
                // Nullable first. The form posts "" when paying by card,
                // ConvertEmptyStringsToNull turns that into null, and the
                // validator skips a rule only for "" — not for null. So
                // `exists` ran on the null and answered "the selected plan
                // term id is invalid" on a checkout that never had a plan.
                'nullable',
                Rule::requiredIf(fn () => $request->input('payment_method') === CheckoutMethod::PaySmallSmall->value),
                Rule::exists('plan_terms', 'id')->where('is_active', true),
            ],
            // How many instalments to settle at checkout. Bounded against the
            // chosen term below, once it is known.
            'upfront_installments' => ['nullable', 'integer', 'min:1', 'max:120'],
            // Present only for a Buy-now checkout.
            'buy_now_product' => ['nullable', 'string'],
            'buy_now_quantity' => ['nullable', 'integer', 'min:1'],
        ], [
            'country_id.exists' => 'Select a valid country.',
            'recipient_phone.regex' => 'Enter a valid Nigerian phone number, e.g. 08031234567.',
            'state.in' => 'Choose a state from the list.',
            'plan_term_id.required' => 'Choose how you want to pay it off.',
            'plan_term_id.exists' => 'That plan is no longer available. Pick one of the plans listed.',
        ]);

        $method = CheckoutMethod::from($validated['payment_method']);

        if (! $method->isAvailable()) {
            throw ValidationException::withMessages([
                'payment_method' => $method->label().' is not available yet. Please choose another method.',
            ]);
        }

        $user = $request->user();

        // Remember where this went, so the next checkout opens prefilled
        // instead of asking for the same address again.
        $this->rememberAddress($user, $validated);

        $buyNow = $this->buyNowRequest($request);

        $lines = $buyNow
            ? $cartService->buyNowLines($buyNow['product'], $buyNow['quantity'])
            : $cartService->lines($user);

        // Pay Small Small charges nothing today: it locks the price into a
        // plan and hands the cart over. Instalments are paid from the plan
        // page, and delivery follows the last one.
        if ($method === CheckoutMethod::PaySmallSmall) {
            $term = PlanTerm::query()->findOrFail($validated['plan_term_id']);
            $goal = $goalService->createFromLines($user, $lines, $validated, $term);

            // A Buy-now plan covers only the item the shopper picked, so the
            // cart they built separately survives.
            if (! $buyNow) {
                $cartService->clear($user);
            }

            // A term that pays up front is not started until the money lands,
            // so the customer goes straight to Paystack for the instalments
            // they chose to settle now. Credit rolled in at creation may
            // already have covered it, hence the remaining check.
            if ($term->paysUpfront() && $goal->remainingKobo() > 0) {
                $dueNow = min(
                    $goal->installment_kobo * $this->upfrontInstallments($request, $term),
                    $goal->remainingKobo(),
                );

                return $startPayment->forPlanInstallment($user, $goal, $dueNow);
            }

            return redirect()
                ->route('savings.goals.show', $goal->uuid)
                ->with(
                    'success',
                    $term->paysUpfront()
                        ? 'Plan started — your credit covered the first payment.'
                        : 'Price locked. Your first payment is due by '
                            .$goal->first_payment_due_at?->format('j M Y').'.',
                );
        }

        $payOnDelivery = $method === CheckoutMethod::PayOnDelivery;

        // Re-checked here, not trusted from the page. The cap, the states and
        // the switch itself can all change between the checkout screen
        // rendering and this request arriving, and a shopper must not be able
        // to post their way past a limit by keeping a stale tab open.
        if ($payOnDelivery) {
            $summary = CartSummary::fromLines($lines, $validated['state']);
            $reason = PayOnDeliveryPolicy::refusalReason($user, $summary->subtotalKobo, $validated['state']);

            if ($reason !== null) {
                throw ValidationException::withMessages(['payment_method' => $reason]);
            }
        }

        // Card: freeze the basket into a pending session, then hand the
        // shopper to Paystack. Only the verified webhook raises the orders,
        // so nothing is created here that a failed payment would strand.
        //
        // Pay on delivery goes the same way — it still charges the delivery
        // fee now — so the webhook remains the only thing that raises orders.
        //
        // The code comes from the session, never the form: a posted code
        // would let anyone skip the apply endpoint and its rate limit.
        $session = $checkoutService->startCardCheckout(
            $user,
            $lines,
            $validated,
            $request->session()->get('cart.promo_code'),
            $payOnDelivery,
        );

        // Consumed either way. If the charge fails the shopper re-applies it;
        // leaving it in the session means a second, unrelated checkout would
        // silently inherit a discount nobody asked for.
        $request->session()->forget('cart.promo_code');

        return $startPayment->forCheckout($user, $session);
    }

    /**
     * How many instalments the customer chose to settle at checkout.
     *
     * Clamped to the term rather than validated against it: the count is a
     * convenience ("pay two months now, one to go"), and a nonsense figure
     * should settle one instalment, not fail a checkout the customer has
     * already committed to.
     */
    private function upfrontInstallments(Request $request, PlanTerm $term): int
    {
        $requested = (int) ($request->input('upfront_installments') ?? 1);

        return max(1, min($requested, $term->installments));
    }

    /**
     * Try a promo code against the current cart.
     *
     * Held in the session rather than written to the database: an applied
     * code is an intention, not a commitment, and a shopper who types three
     * codes and closes the tab should leave nothing behind. It is re-quoted
     * at checkout, so nothing here can be trusted into a price.
     *
     * Rate-limited on the route. Codes are short and guessable by design —
     * they are printed on flyers — so the throttle, not secrecy, is what
     * stops someone grinding through the keyspace.
     */
    public function applyPromo(Request $request, CartService $cartService, PromoRedeemer $redeemer): RedirectResponse
    {
        $validated = $request->validate([
            'promo_code' => ['required', 'string', 'max:32'],
        ]);

        $lines = $cartService->lines($request->user());

        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['promo_code' => 'Your cart is empty.']);
        }

        $summary = CartSummary::fromLines($lines, $request->user()->customerProfile?->default_state);

        // Throws with a message meant for the shopper if the code will not do.
        $quote = $redeemer->quote(
            $request->user(),
            $validated['promo_code'],
            $summary->subtotalKobo,
            $summary->shippingKobo,
            CartSummary::commissionKoboFor($lines),
        );

        $request->session()->put('cart.promo_code', $quote['code']->code);

        return back()->with('success', $quote['code']->label().' applied.');
    }

    public function removePromo(Request $request): RedirectResponse
    {
        $request->session()->forget('cart.promo_code');

        return back()->with('success', 'Promo code removed.');
    }

    /**
     * The code the shopper applied, re-quoted against the cart as it stands.
     *
     * Returns null — and quietly drops the code — if it no longer applies.
     * The cart can change after a code is applied (an item removed takes the
     * basket under the minimum), and a checkout page showing a discount the
     * charge will not honour is worse than one that never showed it.
     *
     * @param  Collection<int, array{cartItemId: int|null, product: Product, quantity: int}>  $lines
     * @return array{code: string, label: string, discountKobo: int, deliveryDiscountKobo: int}|null
     */
    private function appliedPromo(Request $request, Collection $lines, CartSummary $summary): ?array
    {
        $code = $request->session()->get('cart.promo_code');

        if ($code === null || $lines->isEmpty()) {
            return null;
        }

        try {
            $quote = app(PromoRedeemer::class)->quote(
                $request->user(),
                $code,
                $summary->subtotalKobo,
                $summary->shippingKobo,
                CartSummary::commissionKoboFor($lines),
            );
        } catch (ValidationException) {
            $request->session()->forget('cart.promo_code');

            return null;
        }

        return [
            'code' => $quote['code']->code,
            'label' => $quote['code']->label(),
            'discountKobo' => $quote['discountKobo'],
            'deliveryDiscountKobo' => $quote['deliveryDiscountKobo'],
        ];
    }

    /**
     * Save the delivery address on its own, without placing an order.
     *
     * Backs the "Save address" button in the checkout modal, so an address
     * survives even if the shopper never finishes paying.
     */
    public function saveAddress(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'recipient_name' => ['required', 'string', 'max:120'],
            'recipient_phone' => ['required', 'string', 'regex:/^(\+?234|0)[789][01]\d{8}$/'],
            'delivery_address' => ['required', 'string', 'max:500'],
            'state' => ['required', 'string', Rule::in(Nigeria::STATES)],
            'lga' => ['required', 'string', 'max:80'],
            'landmark' => ['nullable', 'string', 'max:160'],
        ], [
            'recipient_phone.regex' => 'Enter a valid Nigerian phone number, e.g. 08031234567.',
            'state.in' => 'Choose a state from the list.',
        ]);

        $this->rememberAddress($request->user(), $validated);

        return back()->with('success', 'Delivery address saved.');
    }

    /**
     * Store the address as this customer's default for next time.
     *
     * @param  array<string, mixed>  $address
     */
    private function rememberAddress(User $user, array $address): void
    {
        CustomerProfile::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'default_recipient_name' => $address['recipient_name'],
                'default_recipient_phone' => $address['recipient_phone'],
                'default_address' => $address['delivery_address'],
                'default_state' => $address['state'],
                'default_lga' => $address['lga'],
                'default_landmark' => $address['landmark'] ?? null,
            ],
        );
    }

    /**
     * "Buy now" resolves to a one-item basket that never touches the cart.
     * Returns null for an ordinary cart checkout.
     *
     * Only approved, in-stock products qualify; anything else falls back to
     * the cart rather than 404ing someone mid-purchase.
     *
     * @return array{product: Product, quantity: int}|null
     */
    private function buyNowRequest(Request $request): ?array
    {
        $uuid = $request->input('buy_now') ?? $request->input('buy_now_product');

        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        $product = Product::query()
            ->where('uuid', $uuid)
            ->where('status', ProductStatus::Approved)
            ->first();

        if ($product === null || $product->stock_quantity < 1) {
            return null;
        }

        $quantity = (int) ($request->input('qty') ?? $request->input('buy_now_quantity') ?? 1);

        return [
            'product' => $product,
            'quantity' => max(1, min($quantity, $product->stock_quantity)),
        ];
    }

    /**
     * One row shape for both the cart and checkout screens.
     *
     * @param  Collection<int, array{cartItemId: int|null, product: Product, quantity: int}>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function presentLines(Collection $lines): array
    {
        return $lines->map(function (array $line) {
            /** @var Product $product */
            $product = $line['product'];

            return [
                'cartItemId' => $line['cartItemId'],
                'productUuid' => $product->uuid,
                'productName' => $product->name,
                'productSlug' => $product->slug,
                'productImage' => $product->primaryImageUrl(),
                'categoryName' => $product->category->name,
                'vendorName' => $product->vendor->business_name,
                'priceKobo' => $product->price_kobo,
                'compareAtPriceKobo' => $product->compare_at_price_kobo,
                'quantity' => $line['quantity'],
                'lineTotalKobo' => $product->price_kobo * $line['quantity'],
                'stockQuantity' => $product->stock_quantity,
            ];
        })->all();
    }
}
