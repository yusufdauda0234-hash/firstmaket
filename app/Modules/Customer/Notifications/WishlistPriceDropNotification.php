<?php

namespace App\Modules\Customer\Notifications;

use App\Modules\Catalog\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;

class WishlistPriceDropNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Product $product,
        private readonly int $oldPriceKobo,
        private readonly int $newPriceKobo,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): DatabaseMessage
    {
        $dropPercent = (int) round((1 - ($this->newPriceKobo / $this->oldPriceKobo)) * 100);

        return new DatabaseMessage([
            'title' => 'A saved item just dropped in price',
            'body' => "{$this->product->name} is now {$dropPercent}% cheaper.",
            'url' => route('catalog.product', $this->product->slug),
        ]);
    }
}