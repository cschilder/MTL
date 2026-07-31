<?php

declare(strict_types=1);

namespace MTL\Http\Controllers\Admin;

use MTL\Core\Request;
use MTL\Core\Response;
use MTL\Http\Controllers\Controller;
use MTL\Models\Tag;
use MTL\Services\AuditService;
use MTL\Support\Str;

defined('MTL_APP') || exit;

/**
 * Tags.
 */
final class TagAdminController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('tag.manage');

        $query = Tag::query()->orderBy('usage_count', 'DESC')->orderBy('name');

        $search = $request->string('q');

        if ($search !== '') {
            $query->whereLike('name', $search);
        }

        $result = $query->paginate($this->page($request), 60);

        return view('admin/tags', [
            'title'      => __('nav.tags'),
            'noindex'    => true,
            'tags'       => Tag::fromRows($result['data']),
            'pagination' => $result,
            'filters'    => ['q' => $search],
        ]);
    }

    public function store(Request $request): Response
    {
        $this->authorize('tag.manage');

        $data = $this->validate($request, [
            'name'  => 'required|string|min:1|max:80',
            'color' => 'nullable|string|regex:^#[0-9a-fA-F]{6}$',
        ]);

        $tag = Tag::findOrCreate((string) $data['name']);

        if (($data['color'] ?? '') !== '') {
            $tag->update(['color' => strtolower((string) $data['color'])]);
        }

        AuditService::log('tag.created', $tag);

        return $this->back(path('/admin/tags'), __('app.saved'));
    }

    public function update(Request $request): Response
    {
        $this->authorize('tag.manage');

        /** @var Tag $tag */
        $tag = $this->findOrFail(Tag::class, (int) $request->param('id', '0'));

        $data = $this->validate($request, [
            'name'  => 'required|string|min:1|max:80',
            'color' => 'nullable|string|regex:^#[0-9a-fA-F]{6}$',
        ]);

        $name = (string) $data['name'];
        $slug = Str::slug($name, 96);

        // Renaming into an existing tag's slug would break the unique index;
        // keeping the old slug is the least surprising outcome.
        if ($slug !== $tag->string('slug') && Tag::query()->where('slug', '=', $slug)->exists()) {
            $slug = $tag->string('slug');
        }

        $tag->update([
            'name'  => $name,
            'slug'  => $slug,
            'color' => ($data['color'] ?? '') === '' ? '' : strtolower((string) $data['color']),
        ]);

        AuditService::log('tag.updated', $tag);

        return $this->respond($request, ['id' => $tag->id()], path('/admin/tags'), __('app.saved'));
    }

    public function destroy(Request $request): Response
    {
        $this->authorize('tag.manage');

        /** @var Tag $tag */
        $tag = $this->findOrFail(Tag::class, (int) $request->param('id', '0'));

        // The pivot cascades, so removing the tag detaches it everywhere.
        $tag->forceDelete();

        AuditService::log('tag.deleted', null, ['name' => $tag->string('name')]);

        return $this->back(path('/admin/tags'), __('app.saved'), 'information');
    }
}
