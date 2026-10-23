<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * System architecture (admin): docs/ARCHITECTURE.md rendered in the app, so the page is always the repo's version.
 * Mermaid diagrams arrive as ```mermaid code blocks and are drawn in the browser (pages/settings/architecture.tsx).
 */
class ArchitectureController extends Controller
{
    public function __invoke(): Response
    {
        $path = base_path('docs/ARCHITECTURE.md');

        return Inertia::render('settings/architecture', [
            // Our own file, but raw HTML is stripped anyway.
            'html' => is_file($path) ? Str::markdown(file_get_contents($path), ['html_input' => 'strip', 'allow_unsafe_links' => false]) : null,
        ]);
    }
}
