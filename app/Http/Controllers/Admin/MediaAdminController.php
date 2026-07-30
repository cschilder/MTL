<?php

declare(strict_types=1);

namespace MTL\Http\Controllers\Admin;

use MTL\Core\QueryBuilder;
use MTL\Core\Request;
use MTL\Core\Response;
use MTL\Http\Controllers\Controller;
use MTL\Markdown\Markdown;
use MTL\Models\Media;
use MTL\Services\AuditService;
use MTL\Services\MediaService;
use MTL\Services\SearchService;

defined('MTL_APP') || exit;

/**
 * The media library.
 */
final class MediaAdminController extends Controller
{
    public function index(Request $request): Response
    {
        $result = $this->paginate($request, false);

        return view('admin/media/index', [
            'title'      => __('media.library'),
            'noindex'    => true,
            'media'      => Media::fromRows($result['data']),
            'pagination' => $result,
            'filters'    => $this->filters($request),
            'usage'      => MediaService::totalStorageBytes(),
        ]);
    }

    public function trash(Request $request): Response
    {
        $this->authorize('media.delete');

        $result = $this->paginate($request, true);

        return view('admin/media/trash', [
            'title'      => __('media.trash'),
            'noindex'    => true,
            'media'      => Media::fromRows($result['data']),
            'pagination' => $result,
        ]);
    }

    public function edit(Request $request): Response
    {
        /** @var Media $media */
        $media = $this->findOrFail(Media::class, (int) $request->param('id', '0'), true);

        $this->authorize('media.update', $media);

        return view('admin/media/edit', [
            'title'   => $media->displayTitle(),
            'noindex' => true,
            'media'   => $media,
            'usedIn'  => $this->usage($media),
            'action'  => path('/admin/media/' . $media->id()),
        ]);
    }

    public function update(Request $request): Response
    {
        /** @var Media $media */
        $media = $this->findOrFail(Media::class, (int) $request->param('id', '0'), true);

        $this->authorize('media.update', $media);

        $data = $this->validate($request, [
            'title'       => 'nullable|string|max:180',
            'alt_text'    => 'nullable|string|max:500',
            'caption_md'  => 'nullable|string|max:20000|raw',
            'credit'      => 'nullable|string|max:180',
            'visibility'  => 'nullable|string|in:public,inherit,private',
            'latitude'    => 'nullable|latitude',
            'longitude'   => 'nullable|longitude',
            'captured_at' => 'nullable|date',
        ]);

        $before = $media->raw();

        $values = [
            'title'      => $data['title'] ?? '',
            'alt_text'   => $data['alt_text'] ?? '',
            'credit'     => $data['credit'] ?? '',
            'visibility' => $data['visibility'] ?? $media->string('visibility'),
        ];

        // A coordinate cleared in the form has to become NULL, not 0,0 — which
        // is a real place in the Atlantic.
        foreach (['latitude', 'longitude', 'captured_at'] as $field) {
            if ($request->has($field)) {
                $values[$field] = ($data[$field] ?? '') === '' ? null : $data[$field];
            }
        }

        if ($request->has('caption_md')) {
            $values['caption_md'] = $data['caption_md'] ?? '';
            $values['caption_html'] = Markdown::renderSnippet((string) ($data['caption_md'] ?? ''));
        }

        $media->update($values);

        SearchService::index($media);

        AuditService::logChange('media.updated', $media, $before, $values);

        return $this->respond($request, ['id' => $media->id()], path('/admin/media/' . $media->id()), __('media.updated'));
    }

    public function destroy(Request $request): Response
    {
        /** @var Media $media */
        $media = $this->findOrFail(Media::class, (int) $request->param('id', '0'), true);

        $this->authorize('media.delete', $media);

        // Two stages: the first delete moves it to the bin, a delete from the
        // bin removes the files. Photographs are not something to lose to a
        // mis-tap.
        if ($media->isDeleted()) {
            MediaService::purge($media);

            return $this->back(path('/admin/media/trash'), __('media.purged'), 'information');
        }

        MediaService::softDelete($media);

        return $this->back(path('/admin/media'), __('media.deleted'), 'information');
    }

    public function restore(Request $request): Response
    {
        /** @var Media $media */
        $media = $this->findOrFail(Media::class, (int) $request->param('id', '0'), true);

        $this->authorize('media.update', $media);

        MediaService::restore($media);

        return $this->back(path('/admin/media/trash'), __('media.restored'));
    }

    /**
     * Applies one action to a selection.
     */
    public function bulk(Request $request): Response
    {
        $action = $request->string('action');
        $ids = array_slice(array_map('intval', $request->array('ids')), 0, 200);

        if ($ids === []) {
            return $this->back(path('/admin/media'), __('app.none'), 'caution');
        }

        $affected = 0;

        foreach ($ids as $id) {
            $media = Media::find($id);

            if ($media === null) {
                continue;
            }

            $permission = $action === 'delete' || $action === 'purge' ? 'media.delete' : 'media.update';

            // Silently skipping is deliberate: a selection that spans other
            // people's uploads should still act on the ones it may.
            if (!$this->auth()->can($permission, $media)) {
                continue;
            }

            match ($action) {
                'delete'   => MediaService::softDelete($media),
                'restore'  => MediaService::restore($media),
                'purge'    => MediaService::purge($media),
                'public'   => $media->update(['visibility' => 'public']),
                'private'  => $media->update(['visibility' => 'private']),
                'inherit'  => $media->update(['visibility' => 'inherit']),
                'rebuild'  => MediaService::rebuildVariants($media),
                default    => null,
            };

            ++$affected;
        }

        AuditService::log('media.bulk_' . $action, null, ['count' => $affected]);

        return $this->respond(
            $request,
            ['affected' => $affected],
            path($action === 'restore' || $action === 'purge' ? '/admin/media/trash' : '/admin/media'),
            __('media.updated')
        );
    }

    // -------------------------------------------------------------------------

    /**
     * @return array{data:list<array<string,mixed>>,total:int,page:int,per_page:int,pages:int}
     */
    private function paginate(Request $request, bool $trashed): array
    {
        $user = $this->requireUser();

        $query = Media::query();

        $query = $trashed ? $query->whereNotNull('deleted_at') : $query->whereNull('deleted_at');

        if (!$user->isEditor()) {
            $query->where('user_id', '=', $user->id());
        }

        $filters = $this->filters($request);

        if ($filters['q'] !== '') {
            $query->whereGroup(static function (QueryBuilder $q) use ($filters): void {
                $q->whereLike('title', $filters['q'])
                    ->whereLike('original_name', $filters['q'], 'OR')
                    ->whereLike('alt_text', $filters['q'], 'OR');
            });
        }

        if ($filters['kind'] !== '') {
            $query->where('kind', '=', $filters['kind']);
        }

        if ($filters['status'] === 'failed') {
            $query->where('status', '=', 'failed');
        }

        if ($filters['orphans'] === '1') {
            $prefix = db()->prefix();

            $query->whereRaw(
                "NOT EXISTS (SELECT 1 FROM `{$prefix}step_media` sm WHERE sm.media_id = `{$prefix}media`.`id`)
                 AND NOT EXISTS (SELECT 1 FROM `{$prefix}album_media` am WHERE am.media_id = `{$prefix}media`.`id`)"
            );
        }

        return $query->latest('created_at')->paginate($this->page($request), 48);
    }

    /**
     * @return array{q:string,kind:string,status:string,orphans:string}
     */
    private function filters(Request $request): array
    {
        $kind = $request->string('kind');

        return [
            'q'       => $request->string('q'),
            'kind'    => in_array($kind, ['image', 'video', 'audio', 'document'], true) ? $kind : '',
            'status'  => $request->string('status'),
            'orphans' => $request->string('orphans'),
        ];
    }

    /**
     * Where a file is used, so the edit screen can warn before it is deleted.
     *
     * @return array{steps:list<array<string,mixed>>,albums:list<array<string,mixed>>}
     */
    private function usage(Media $media): array
    {
        return [
            'steps' => db()->table('steps')
                ->select('steps.id', 'steps.title', 'steps.trip_id')
                ->join('step_media', 'step_media.step_id', '=', 'steps.id')
                ->where('step_media.media_id', '=', $media->id())
                ->whereNull('steps.deleted_at')
                ->limit(50)
                ->get(),
            'albums' => db()->table('albums')
                ->select('albums.id', 'albums.title')
                ->join('album_media', 'album_media.album_id', '=', 'albums.id')
                ->where('album_media.media_id', '=', $media->id())
                ->whereNull('albums.deleted_at')
                ->limit(50)
                ->get(),
        ];
    }
}
