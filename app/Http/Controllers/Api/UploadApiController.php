<?php

declare(strict_types=1);

namespace MTL\Http\Controllers\Api;

use MTL\Core\HttpException;
use MTL\Core\Request;
use MTL\Core\Response;
use MTL\Http\Controllers\Controller;
use MTL\Services\MediaService;
use MTL\Services\UploadService;

defined('MTL_APP') || exit;

/**
 * The chunked upload endpoints.
 *
 * The browser calls init once, chunk once per part, and complete at the end.
 * Every call is authorised and scoped to the person who started the upload.
 */
final class UploadApiController extends Controller
{
    public function init(Request $request): Response
    {
        $user = $this->requireUser();
        $this->authorize('media.upload');

        $data = $this->validate($request, [
            'name'        => 'required|string|max:255',
            'mime'        => 'nullable|string|max:100',
            'size'        => 'required|int|min:1',
            'target_type' => 'nullable|string|in:none,step,album,trip,avatar',
            'target_id'   => 'nullable|int',
        ]);

        // Attaching to something means being allowed to edit that something.
        $this->assertTargetPermitted((string) ($data['target_type'] ?? 'none'), (int) ($data['target_id'] ?? 0));

        $session = UploadService::begin(
            $user,
            (string) $data['name'],
            (string) ($data['mime'] ?? ''),
            (int) $data['size'],
            [
                'type' => (string) ($data['target_type'] ?? 'none'),
                'id'   => (int) ($data['target_id'] ?? 0),
            ]
        );

        return Response::json($session);
    }

    public function chunk(Request $request): Response
    {
        $user = $this->requireUser();

        $uuid = $request->string('uuid');
        $index = $request->int('index', -1);

        $file = $request->file('chunk');

        if ($file === null) {
            throw new HttpException(422, $this->describeUploadError($request));
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new HttpException(422, $this->uploadErrorMessage($file['error']));
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            // The only way this happens is a forged path in the request.
            throw HttpException::forbidden();
        }

        $progress = UploadService::receiveChunk($user, $uuid, $index, $file['tmp_name']);

        return Response::json($progress);
    }

    public function complete(Request $request): Response
    {
        $user = $this->requireUser();

        $media = UploadService::complete($user, $request->string('uuid'));

        return Response::json([
            'ok'    => true,
            'media' => [
                'id'    => $media->id(),
                'uuid'  => $media->string('uuid'),
                'kind'  => $media->string('kind'),
                'title' => $media->displayTitle(),
                'alt'   => $media->alt(),
                'thumb' => $media->url('thumb'),
                'url'   => $media->url('large'),
                'color' => $media->string('dominant_color'),
                'width' => $media->int('width'),
                'height' => $media->int('height'),
                'status' => $media->string('status'),
                'error'  => $media->string('processing_error'),
            ],
        ]);
    }

    public function status(Request $request): Response
    {
        $user = $this->requireUser();

        return Response::json(UploadService::status($user, (string) $request->param('uuid', '')));
    }

    public function abort(Request $request): Response
    {
        $user = $this->requireUser();

        UploadService::abort($user, (string) $request->param('uuid', ''));

        return Response::json(['ok' => true]);
    }

    // -------------------------------------------------------------------------

    /**
     * Refuses an upload aimed at something the visitor may not edit.
     */
    private function assertTargetPermitted(string $type, int $id): void
    {
        if ($type === 'none' || $id <= 0) {
            return;
        }

        $subject = match ($type) {
            'step'   => \MTL\Models\Step::find($id),
            'album'  => \MTL\Models\Album::find($id),
            'trip'   => \MTL\Models\Trip::find($id),
            'avatar' => \MTL\Models\User::find($id),
            default  => null,
        };

        if ($subject === null) {
            throw HttpException::notFound();
        }

        if ($type === 'avatar') {
            // Only your own avatar, unless you manage users.
            if ($subject->id() !== $this->requireUser()->id() && !$this->auth()->can('user.manage', $subject)) {
                throw HttpException::forbidden();
            }

            return;
        }

        $this->authorize($type . '.update', $subject);
    }

    /**
     * A POST that exceeds post_max_size arrives with empty $_POST and $_FILES
     * and no error code, which otherwise looks like a missing field.
     */
    private function describeUploadError(Request $request): string
    {
        $contentLength = (int) ($request->header('content-length') ?? 0);
        $limit = $this->bytes((string) ini_get('post_max_size'));

        if ($limit > 0 && $contentLength > $limit) {
            return sprintf(
                'This part is larger than the server accepts (%s). Lower media.chunk_bytes in the configuration.',
                ini_get('post_max_size')
            );
        }

        return 'No chunk was received.';
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'This part is larger than upload_max_filesize allows.',
            UPLOAD_ERR_PARTIAL    => 'The part arrived incomplete. It will be retried.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary directory for uploads.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded part to disk.',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension refused the upload.',
            default               => 'The part could not be received.',
        };
    }

    private function bytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '-1') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g'     => $number * 1024 ** 3,
            'm'     => $number * 1024 ** 2,
            'k'     => $number * 1024,
            default => $number,
        };
    }
}
