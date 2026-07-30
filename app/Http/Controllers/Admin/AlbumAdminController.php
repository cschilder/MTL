<?php

declare(strict_types=1);

namespace MTL\Http\Controllers\Admin;

use MTL\Core\Request;
use MTL\Core\Response;
use MTL\Http\Controllers\Controller;
use MTL\Models\Album;
use MTL\Models\Trip;
use MTL\Services\AlbumService;
use MTL\Services\MediaService;

defined('MTL_APP') || exit;

/**
 * Managing albums.
 */
final class AlbumAdminController extends Controller
{
    private const RULES = [
        'title'          => 'required|string|min:1|max:180',
        'slug'           => 'nullable|slug|max:180',
        'description_md' => 'nullable|string|max:100000|raw',
        'trip_id'        => 'nullable|int',
        'step_id'        => 'nullable|int',
        'status'         => 'nullable|string|in:draft,published',
        'visibility'     => 'nullable|string|in:inherit,public,unlisted,private',
        'layout'         => 'nullable|string|in:grid,masonry,story',
        'cover_media_id' => 'nullable|int',
        'position'       => 'nullable|int',
    ];

    public function index(Request $request): Response
    {
        $user = $this->requireUser();

        $query = Album::active()->latest('updated_at');

        if (!$user->isEditor()) {
            $query->where('user_id', '=', $user->id());
        }

        $search = $request->string('q');

        if ($search !== '') {
            $query->whereLike('title', $search);
        }

        $result = $query->paginate($this->page($request), 25);

        return view('admin/albums/index', [
            'title'      => __('album.albums'),
            'noindex'    => true,
            'albums'     => Album::fromRows($result['data']),
            'pagination' => $result,
            'filters'    => ['q' => $search],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('album.create');

        return view('admin/albums/edit', [
            'title'   => __('album.new'),
            'noindex' => true,
            'album'   => null,
            'media'   => [],
            'trips'   => $this->selectableTrips(),
            'action'  => path('/admin/albums'),
        ]);
    }

    public function store(Request $request): Response
    {
        $this->authorize('album.create');

        $data = $this->validate($request, self::RULES);

        $album = AlbumService::create($data, $this->requireUser());

        $ids = array_map('intval', $request->array('media'));

        if ($ids !== []) {
            MediaService::attachToAlbum($album, $ids);
        }

        return $this->back($album->editUrl(), __('album.created'));
    }

    public function edit(Request $request): Response
    {
        /** @var Album $album */
        $album = $this->findOrFail(Album::class, (int) $request->param('id', '0'), true);

        $this->authorize('album.update', $album);

        return view('admin/albums/edit', [
            'title'   => $album->string('title'),
            'noindex' => true,
            'album'   => $album,
            'media'   => $album->media(),
            'cover'   => $album->coverMedia(),
            'trips'   => $this->selectableTrips(),
            'action'  => path('/admin/albums/' . $album->id()),
        ]);
    }

    public function update(Request $request): Response
    {
        /** @var Album $album */
        $album = $this->findOrFail(Album::class, (int) $request->param('id', '0'), true);

        $this->authorize('album.update', $album);

        AlbumService::update($album, $this->validate($request, self::RULES));

        return $this->respond($request, ['id' => $album->id()], $album->editUrl(), __('album.updated'));
    }

    public function destroy(Request $request): Response
    {
        /** @var Album $album */
        $album = $this->findOrFail(Album::class, (int) $request->param('id', '0'), true);

        $this->authorize('album.delete', $album);

        AlbumService::delete($album);

        return $this->back(path('/admin/albums'), __('album.deleted'), 'information');
    }

    public function attachMedia(Request $request): Response
    {
        /** @var Album $album */
        $album = $this->findOrFail(Album::class, (int) $request->param('id', '0'));

        $this->authorize('album.update', $album);

        $added = MediaService::attachToAlbum($album, array_map('intval', $request->array('media')));

        return $this->respond($request, ['added' => $added], $album->editUrl(), __('album.updated'));
    }

    public function detachMedia(Request $request): Response
    {
        /** @var Album $album */
        $album = $this->findOrFail(Album::class, (int) $request->param('id', '0'));

        $this->authorize('album.update', $album);

        MediaService::detachFromAlbum($album, (int) $request->param('media', '0'));

        return $this->respond($request, [], $album->editUrl(), __('album.updated'));
    }

    public function reorderMedia(Request $request): Response
    {
        /** @var Album $album */
        $album = $this->findOrFail(Album::class, (int) $request->param('id', '0'));

        $this->authorize('album.update', $album);

        MediaService::reorder('album_media', 'album_id', $album, array_map('intval', $request->array('order')));

        return $this->respond($request, [], $album->editUrl(), __('album.updated'));
    }

    /**
     * Trips the current user may hang an album from.
     *
     * @return list<Trip>
     */
    private function selectableTrips(): array
    {
        $user = $this->requireUser();

        $query = Trip::active()->orderBy('title');

        if (!$user->isEditor()) {
            $query->where('user_id', '=', $user->id());
        }

        return Trip::fromRows($query->limit(500)->get());
    }
}
