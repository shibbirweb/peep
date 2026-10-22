<?php

namespace App\Http\Controllers;

class ProductController
{
    public function show(string $slug)
    {
        $product = ['id' => 42, 'name' => 'Espresso beans', 'price' => 18.0, 'stock' => 7, 'tags' => ['coffee', 'whole bean']];

        peep($product)->label('Product');

        peep()->payload('query', ['sql' => "select * from `products` where `slug` = 'espresso-beans' limit 1", 'bindings' => [], 'time_ms' => 1.4, 'connection' => 'mysql', 'driver' => 'mysql']);
        peep()->payload('query', ['sql' => 'select * from `reviews` where `product_id` = 42', 'bindings' => [], 'time_ms' => 2.1, 'connection' => 'mysql', 'driver' => 'mysql', 'duplicate_count' => 3]);

        $reviews = [
            ['user' => 'Ada', 'rating' => 5, 'title' => 'Rich and smooth'],
            ['user' => 'Grace', 'rating' => 4, 'title' => 'Great crema'],
            ['user' => 'Linus', 'rating' => 5, 'title' => 'Best beans so far'],
        ];

        peep()->table($reviews, 'Reviews');

        return $product;
    }
}
