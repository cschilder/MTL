<?php

declare(strict_types=1);

namespace MTL\Http\Controllers\Api;

use MTL\Core\QueryBuilder;
use MTL\Core\Request;
use MTL\Core\Response;
use MTL\Http\Controllers\Controller;
use MTL\Models\Media;

defined('MTL_APP') || exit;

/**
 * The media library as JSON, for the picker and the library grid.
 */
final class MediaApiController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->requireUser();

        $query = Media::active()->latest('created_at');

        // An author only browses their own library; an editor sees everything.
        if (!$user->isEditor()) {
            $query->where('user_id', '=', $user->id());
        }

        $search = $request->string('q');

        if ($search !== '') {
            $query->whereGroup(static function (QueryBuilder $q) use ($search): void {
                $q->whereLike('title', $search)
                    ->whereLike('original_name', $search, 'OR')
                    ->whereLike('alt_text', $search, 'OR');
            });
        }

        $kind = $request->string('kind');

        if (in_array($kind, ['image', 'video', 'audio', 'document'], true)) {
            $query->where('kind', '=', $kind);
        }

        if ($request->string('unused') === '1') {
            // Media not attached to any step or album, which is what a tidy-up
            // starts from.
            $prefix = db()->prefix();

            $query->whereRaw(
                "NOT EXISTS (SELECT 1 FROM `{$prefix}step_media` sm WHERE sm.media_id = `{$prefix}media`.`id`)
                 AND NOT EXISTS (SELECT 1 FROM `{$prefix}album_media` am WHERE am.media_id = `{$prefix}media`.`id`)"
            );
        }

        $page = $this->page($request);
        $result = $query->paginate($page, min(100, max(12, $request->int('per_page', 40))));

        return Response::json([
            'data'     => array_map([$this, 'present'], $result['data']),
            'total'    => $result['total'],
            'page'     => $result['page'],
            'pages'    => $result['pages'],
            'per_page' => $result['per_page'],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request): Response
    {
        $media = $this->findOrFail(Media::class, (int) $request->param('id', '0'));

        $this->authorize('media.update', $media);

        /** @var Media $media */
        return Response::json(['data' => $this->present($media->raw(), true)]);
    }

    /**
     * The shape the front end expects. Deliberately small: the picker renders
     * dozens of these at a time.
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function present(array $row, bool $detailed = false): array
    {
        $media = Media::fromRow($row);

        $payload = [
            'id'      => $media->id(),
            'uuid'    => $media->string('uuid'),
            'kind'    => $media->string('kind'),
            'title'   => $media->displayTitle(),
            'alt'     => $media->alt(),
            'thumb'   => $media->url('thumb'),
            'url'     => $media->url('large'),
            'color'   => $media->string('dominant_color'),
            'width'   => $media->int('width'),
            'height'  => $media->int('height'),
            'size'    => $media->sizeLabel(),
            'created' => $media->date('created_at')?->format('c'),
        ];

        if ($media->isVideo()) {
            $payload['duration'] = $media->durationLabel();
        }

        if (!$detailed) {
            return $payload;
        }

        return $payload + [
            'original_name' => $media->string('original_name'),
            'mime_type'     => $media->string('mime_type'),
            'size_bytes'    => $media->int('size_bytes'),
            'captured_at'   => $media->date('captured_at')?->format('c'),
            'camera'        => $media->cameraLabel(),
            'exposure'      => $media->string('exposure'),
            'iso'           => $media->int('iso'),
            'focal_length'  => $media->string('focal_length'),
            'latitude'      => $media->float('latitude'),
            'longitude'     => $media->float('longitude'),
            'caption'       => $media->string('caption_md'),
            'credit'        => $media->string('credit'),
            'visibility'    => $media->string('visibility'),
            'variants'      => array_keys($media->json('variants')),
            'placeholder'   => $media->string('placeholder'),
        ];
    }
}
