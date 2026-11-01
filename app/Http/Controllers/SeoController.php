<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Response;

class SeoController extends Controller
{
    public function sitemap(): Response
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->whereHas('products', fn ($q) => $q->visible())
            ->orderBy('sort')
            ->get();

        $products = Product::query()
            ->visible()
            ->orderByDesc('updated_at')
            ->get();

        $staticPages = ['faq', 'contact', 'terms', 'refund', 'privacy'];

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'."\n";

        // Homepage
        $xml .= $this->urlNode(
            route('home'),
            now()->toAtomString(),
            'hourly',
            '1.0'
        );

        // Categories (clean SEO URLs)
        foreach ($categories as $category) {
            $xml .= $this->urlNode(
                route('categories.show', $category),
                ($category->updated_at ?? now())->toAtomString(),
                'daily',
                '0.8'
            );
        }

        // Products + Google Image Sitemap nodes
        foreach ($products as $product) {
            $image = $product->imageUrl();
            $xml .= $this->urlNode(
                route('products.show', $product),
                ($product->updated_at ?? now())->toAtomString(),
                'daily',
                '0.9',
                $image,
                $product->name
            );
        }

        // Static Pages
        foreach ($staticPages as $page) {
            $xml .= $this->urlNode(
                route('pages.show', $page),
                now()->startOfMonth()->toAtomString(),
                'monthly',
                '0.5'
            );
        }

        $xml .= '</urlset>';

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=1800',
        ]);
    }

    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
            'Allow: /',
            'Disallow: /admin',
            'Disallow: /checkout/',
            'Disallow: /orders/',
            'Disallow: /account/',
            'Disallow: /api/',
            'Disallow: /webhooks/',
            'Disallow: /track',
            'Disallow: /login',
            'Disallow: /register',
            '',
            'Sitemap: '.url('/sitemap.xml'),
        ];

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    private function urlNode(
        string $loc,
        string $lastmod,
        string $changefreq,
        string $priority,
        ?string $imageUrl = null,
        ?string $imageTitle = null
    ): string {
        $locEsc = htmlspecialchars($loc, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $node = "  <url>\n";
        $node .= "    <loc>{$locEsc}</loc>\n";
        $node .= "    <lastmod>{$lastmod}</lastmod>\n";
        $node .= "    <changefreq>{$changefreq}</changefreq>\n";
        $node .= "    <priority>{$priority}</priority>\n";

        if ($imageUrl) {
            $imgEsc = htmlspecialchars($imageUrl, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $titleEsc = htmlspecialchars((string) $imageTitle, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $node .= "    <image:image>\n";
            $node .= "      <image:loc>{$imgEsc}</image:loc>\n";
            if ($titleEsc !== '') {
                $node .= "      <image:title>{$titleEsc}</image:title>\n";
            }
            $node .= "    </image:image>\n";
        }

        $node .= "  </url>\n";

        return $node;
    }
}
