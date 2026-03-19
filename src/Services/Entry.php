<?php

declare(strict_types=1);

namespace ZephyrPHP\Cms\Services;

/**
 * Short alias for EntryQuery — provides clean, expressive syntax.
 *
 * Usage:
 *   $posts = Entry::collection('blog')->where('status', 'published')->get();
 *   $post  = Entry::collection('blog')->with('author')->find(42);
 *   $id    = Entry::collection('blog')->create(['title' => 'Hello']);
 */
class Entry extends EntryQuery
{
}
