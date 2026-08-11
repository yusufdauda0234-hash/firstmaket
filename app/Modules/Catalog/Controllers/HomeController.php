<?php

namespace App\Modules\Catalog\Controllers;

use App\Modules\Catalog\Services\HomeDataService;
use Inertia\Inertia;
use Inertia\Response;

class HomeController
{
    public function __invoke(HomeDataService $home): Response
    {
        return Inertia::render('Public/Home', [
            'categories' => $home->categories(),
            'featuredProducts' => $home->featuredProducts(),
            'newestProducts' => $home->newestProducts(),
            'campaignProducts' => $home->campaignProducts(),
            'trendingProducts' => $home->trendingProducts(),
            'trendingSearches' => $home->trendingSearches(),
            'heroSlides' => $home->heroSlides(),
            'recentOrderCount' => $home->recentOrderCount(),
            'supportHotline' => config('firstmaket.support.hotline'),
        ]);
    }
}
