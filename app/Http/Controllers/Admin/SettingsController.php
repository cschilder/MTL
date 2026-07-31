<?php

declare(strict_types=1);

namespace MTL\Http\Controllers\Admin;

use MTL\Core\Request;
use MTL\Core\Response;
use MTL\Core\Translator;
use MTL\Http\Controllers\Controller;
use MTL\Services\SettingsService;

defined('MTL_APP') || exit;

/**
 * Site settings.
 */
final class SettingsController extends Controller
{
    /**
     * Which keys each group accepts, and how each is validated.
     *
     * Nothing outside this map can be written, so a crafted form field cannot
     * introduce a setting the application never intended to have.
     *
     * @var array<string,array<string,string>>
     */
    private const FIELDS = [
        'general' => [
            'site.title'       => 'required|string|max:120',
            'site.tagline'     => 'nullable|string|max:200',
            'site.description' => 'nullable|string|max:500',
            'site.owner_name'  => 'nullable|string|max:120',
            'site.language'    => 'nullable|string|max:8',
            'site.timezone'    => 'nullable|string|timezone',
            'site.public'      => 'nullable|bool',
            'site.logo_media_id' => 'nullable|int',
        ],
        'appearance' => [
            'site.theme'  => 'nullable|string|in:auto,light,dark',
            'site.accent' => 'nullable|string|regex:^#[0-9a-fA-F]{6}$',
        ],
        'globe' => [
            'globe.default_view'    => 'nullable|string|in:globe,list',
            'globe.auto_rotate'     => 'nullable|bool',
            'globe.show_graticule'  => 'nullable|bool',
            'globe.show_terminator' => 'nullable|bool',
            'globe.marker_scale'    => 'nullable|numeric|between:0.3,3',
            'globe.resolution'      => 'nullable|string|in:low,high',
        ],
        'media' => [
            'media.default_visibility' => 'nullable|string|in:public,inherit,private',
            'media.strip_gps'          => 'nullable|bool',
            'media.download_original'  => 'nullable|bool',
            'media.quota_mb'           => 'nullable|int|between:0,1048576',
        ],
        'users' => [
            'registration.open'         => 'nullable|bool',
            'registration.default_role' => 'nullable|string|in:admin,editor,author,viewer',
        ],
        'android' => [
            'android.package_name'        => 'nullable|string|max:120|regex:^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$',
            // A comma-separated list of colon-separated hex bytes, as
            // `keytool -list` prints them.
            'android.sha256_fingerprints' => 'nullable|string|max:2000',
        ],
        'maintenance' => [
            'maintenance.enabled' => 'nullable|bool',
            'maintenance.message' => 'nullable|string|max:500',
        ],
    ];

    public function index(Request $request): Response
    {
        $group = (string) $request->param('group', 'general');

        if (!isset(self::FIELDS[$group])) {
            $group = 'general';
        }

        return view('admin/settings', [
            'title'    => __('settings.settings'),
            'noindex'  => true,
            'group'    => $group,
            'groups'   => array_keys(self::FIELDS),
            'values'   => SettingsService::group($group),
            'fields'   => array_keys(self::FIELDS[$group]),
            'locales'  => Translator::available(),
            'action'   => path('/admin/settings'),
        ]);
    }

    public function update(Request $request): Response
    {
        $group = $request->string('group', 'general');

        if (!isset(self::FIELDS[$group])) {
            return $this->back(path('/admin/settings'), __('error.bad_request'), 'negative');
        }

        $rules = self::FIELDS[$group];

        // The form posts flat names (site_title) because a dot is awkward in
        // an HTML name attribute; map them back before validating.
        $input = [];

        foreach (array_keys($rules) as $key) {
            $flat = str_replace('.', '_', $key);

            if ($request->has($flat)) {
                $input[$key] = $request->input($flat);
                continue;
            }

            // An unchecked checkbox sends nothing at all, which has to mean
            // false rather than "leave it alone".
            if (str_contains($rules[$key], 'bool')) {
                $input[$key] = false;
            }
        }

        $validated = \MTL\Support\Validator::make($input, $rules)->validated();

        SettingsService::setMany($validated);
        SettingsService::flush();

        return $this->back(path('/admin/settings/' . $group), __('settings.saved'));
    }
}
