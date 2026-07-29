<?php

declare(strict_types=1);

namespace MTL\Http\Controllers;

use MTL\Core\HttpException;
use MTL\Core\QueryBuilder;
use MTL\Core\Request;
use MTL\Core\Response;
use MTL\Markdown\Markdown;
use MTL\Models\Album;

defined('MTL_APP') || exit;

/**
 * Public album pages.
 */
final class AlbumController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user();

        $query = Album::active()->orderBy('position')->latest('created_at');

        if ($user === null) {
            $query->where('status', '=', 'published')
                ->whereIn('visibility', ['public', 'inherit']);
        } elseif (!$user->isEditor()) {
            $query->whereGroup(static function (QueryBuilder $q) use ($user): void {
                $q->whereGroup(static function (QueryBuilder $inner): void {
                    $inner->where('status', '=', 'published')->whereIn('visibility', ['public', 'inherit']);
                });

                $q->orWhere('user_id', '=', $user->id());
            });
        }

        $result = $query->paginate($this->page($request), 24);

        // The listing query is a fast approximation; visibility that depends on
        // a parent trip is resolved per record here.
        $albums = array_values(array_filter(
            Album::fromRows($result['data']),
            fn (Album $album): bool => $album->isVisibleTo($user)
        ));

        return view('albums/index', [
            'title'      => __('album.albums'),
            'canonical'  => url('/albums'),
            'wide'       => true,
            'albums'     => $albums,
            'pagination' => $result,
        ]);
    }

    public function show(Request $request): Response
    {
        $album = Album::findBySlug((string) $request->param('album', ''));

        if ($album === null || $album->isDeleted()) {
            throw HttpException::notFound();
        }

        $token = $request->string('t');
        $viaShareLink = $token !== '' && hash_equals($album->string('share_token'), $token);

        if (!$viaShareLink && !$album->isVisibleTo($this->user())) {
            throw HttpException::notFound();
        }

        $media = $album->media();
        $cover = $album->coverMedia();

        $description = $album->string('description_html');

        if ($description === '' && $album->string('description_md') !== '') {
            $description = Markdown::renderSnippet($album->string('description_md'));
        }

        return view('albums/show', [
            'title'       => $album->string('title'),
            'description' => \MTL\Support\Str::excerpt(
                \MTL\Support\Str::stripMarkdown($album->string('description_md')),
                200
            ),
            'canonical'   => url($album->url()),
            'ogImage'     => $cover === null ? '' : url($cover->url('large')),
            'noindex'     => $album->string('visibility') !== 'public',
            'wide'        => true,
            'album'       => $album,
            'media'       => $media,
            'descriptionHtml' => $description,
            'parent'      => $album->parent(),
            'canEdit'     => $this->auth()->can('album.update', $album),
        ]);
    }
}
