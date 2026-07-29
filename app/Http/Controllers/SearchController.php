<?php

declare(strict_types=1);

namespace MTL\Http\Controllers;

use MTL\Core\Request;
use MTL\Core\Response;
use MTL\Models\Tag;
use MTL\Services\SearchService;

defined('MTL_APP') || exit;

/**
 * Site search.
 */
final class SearchController extends Controller
{
    public function index(Request $request): Response
    {
        $query = trim($request->string('q'));
        $types = array_values(array_intersect($request->array('type'), ['trip', 'step', 'album', 'media']));

        $page = $this->page($request);
        $perPage = 20;

        $result = $query === ''
            ? ['results' => [], 'total' => 0, 'mode' => 'none']
            : SearchService::search($query, $this->user(), $types, $perPage, ($page - 1) * $perPage);

        return view('search/index', [
            'title'     => $query === '' ? __('app.search') : __('search.results', ['query' => $query]),
            'noindex'   => true,
            'query'     => $query,
            'types'     => $types,
            'results'   => $result['results'],
            'total'     => $result['total'],
            'page'      => $page,
            'pages'     => (int) max(1, ceil($result['total'] / $perPage)),
            'popularTags' => Tag::popular(20),
        ]);
    }

    /**
     * The same search as JSON, for the suggestion drop-down.
     */
    public function api(Request $request): Response
    {
        $query = trim($request->string('q'));

        if ($query === '') {
            return Response::json(['results' => [], 'total' => 0]);
        }

        $result = SearchService::search($query, $this->user(), [], 8);

        return Response::json([
            'results' => array_map(static fn (array $row): array => [
                'type'    => $row['subject_type'],
                'title'   => $row['title'],
                'url'     => $row['url'],
                'snippet' => $row['snippet'],
            ], $result['results']),
            'total' => $result['total'],
        ])->header('Cache-Control', 'private, max-age=30');
    }
}
