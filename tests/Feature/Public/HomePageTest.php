<?php

use Inertia\Testing\AssertableInertia as Assert;

it('shows the home page to guests without authentication', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/Home')
            ->has('categories', 6)
            ->where('categories.0.slug', 'electronics')
            ->has('featuredProducts')
            ->has('newestProducts'));
});

it('shows the catalog to guests with query and category filters applied', function () {
    $this->get('/catalog?query=fridge&category=home-appliances')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/Catalog')
            ->where('filters.query', 'fridge')
            ->has('products.data', 0));
});

it('never exposes unapproved product data on the home page', function () {
    // Sprint 0: product sections must be empty until the approved catalog
    // (Sprint 3) feeds them; this pins the contract.
    $this->get('/')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/Home')
            ->has('featuredProducts', 0)
            ->has('newestProducts', 0));
});
