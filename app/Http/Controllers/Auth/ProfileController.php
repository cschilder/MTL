<?php

declare(strict_types=1);

namespace MTL\Http\Controllers\Auth;

use MTL\Auth\Password;
use MTL\Core\Database;
use MTL\Core\Request;
use MTL\Core\Response;
use MTL\Core\Translator;
use MTL\Http\Controllers\Controller;
use MTL\Models\Media;
use MTL\Services\AuditService;
use MTL\Support\Validator;

defined('MTL_APP') || exit;

/**
 * The signed-in visitor's own account.
 */
final class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $this->requireUser();

        return view('admin/profile', [
            'title'    => __('nav.profile'),
            'noindex'  => true,
            'user'     => $user,
            'avatar'   => $user->int('avatar_media_id') > 0 ? Media::find($user->int('avatar_media_id')) : null,
            'locales'  => Translator::available(),
            'timezones' => $this->commonTimezones(),
        ]);
    }

    public function update(Request $request): Response
    {
        $user = $this->requireUser();

        $data = $this->validate($request, [
            'name'      => 'required|string|max:120',
            'email'     => 'required|email|unique:users,email,' . $user->id(),
            'bio'       => 'nullable|string|max:500',
            'locale'    => 'nullable|string|in:' . implode(',', Translator::available()),
            'timezone'  => 'nullable|string|timezone',
            'avatar_media_id' => 'nullable|int',
        ]);

        $before = $user->raw();

        $values = [
            'name'     => $data['name'],
            'bio'      => $data['bio'] ?? '',
            'locale'   => $data['locale'] ?? $user->string('locale'),
            'timezone' => $data['timezone'] ?? $user->string('timezone'),
        ];

        // Changing the address means it has to be confirmed again, but the
        // account keeps working in the meantime — locking someone out of their
        // own site over a typo would be worse than the risk.
        if (strtolower((string) $data['email']) !== $user->string('email')) {
            $values['email'] = strtolower((string) $data['email']);
            $values['email_verified_at'] = null;
        }

        $avatarId = (int) ($data['avatar_media_id'] ?? 0);

        if ($avatarId > 0) {
            $media = Media::find($avatarId);

            // Only the visitor's own media, so an id cannot be used to attach
            // somebody else's photo to an account.
            if ($media !== null && !$media->isDeleted()
                && ($media->int('user_id') === $user->id() || $user->isEditor())
            ) {
                $values['avatar_media_id'] = $avatarId;
            }
        } elseif ($request->has('avatar_media_id') || $request->string('avatar_media_id') === '0') {
            $values['avatar_media_id'] = null;
        }

        $user->update($values);

        // The interface language follows immediately, before the redirect
        // renders the confirmation.
        \MTL\Core\Session::put('_locale', $user->string('locale'));

        AuditService::logChange('user.profile_updated', $user, $before, $values);

        return $this->back(path('/admin/profile'), __('user.updated'));
    }

    public function changePassword(Request $request): Response
    {
        $user = $this->requireUser();

        $data = Validator::forRequest($request, [
            'current_password' => 'required|string|raw',
            'password'         => 'required|string|password|confirmed|raw',
        ])->validated();

        if (!Password::verify((string) $data['current_password'], $user->string('password_hash'))) {
            return $this->back(path('/admin/profile'), __('auth.password_wrong'), 'negative');
        }

        $user->update(['password_hash' => Password::hash((string) $data['password'])]);

        // Every other device is signed out; this one keeps its session.
        Database::instance()->table('user_tokens')
            ->where('user_id', '=', $user->id())
            ->where('type', '=', 'remember')
            ->delete();

        AuditService::log('user.password_changed', $user);

        return $this->back(path('/admin/profile'), __('auth.password_changed'));
    }

    /**
     * Stores an interface preference. Called by the theme switch, so it has to
     * answer JSON and never redirect.
     */
    public function savePreferences(Request $request): Response
    {
        $user = $this->requireUser();

        $allowed = [
            'theme'        => ['auto', 'light', 'dark'],
            'globe.layer'  => ['none', 'photos', 'rating', 'altitude', 'temperature'],
            'media.view'   => ['grid', 'list'],
        ];

        $saved = [];

        foreach ($allowed as $key => $values) {
            // The key arrives dotted from the client; look it up either way.
            $input = $request->input($key, $request->input(str_replace('.', '_', $key)));

            if ($input === null || $input === '') {
                continue;
            }

            if (!in_array((string) $input, $values, true)) {
                continue;
            }

            $user->setPreference($key, (string) $input);
            $saved[$key] = (string) $input;
        }

        if ($request->wantsJson()) {
            return Response::json(['ok' => true, 'saved' => $saved]);
        }

        return $this->back(path('/admin/profile'), __('app.saved'));
    }

    /**
     * A short list of time zones rather than all four hundred: these cover
     * where a Dutch traveller actually is, and the field accepts any valid
     * identifier typed by hand.
     *
     * @return list<string>
     */
    private function commonTimezones(): array
    {
        return [
            'Europe/Amsterdam', 'Europe/London', 'Europe/Lisbon', 'Europe/Madrid',
            'Europe/Rome', 'Europe/Athens', 'Europe/Istanbul', 'Europe/Moscow',
            'Atlantic/Reykjavik', 'Atlantic/Azores',
            'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles',
            'America/Mexico_City', 'America/Bogota', 'America/Lima', 'America/Santiago',
            'America/Sao_Paulo', 'America/Argentina/Buenos_Aires',
            'Africa/Casablanca', 'Africa/Cairo', 'Africa/Nairobi', 'Africa/Johannesburg',
            'Asia/Dubai', 'Asia/Karachi', 'Asia/Kolkata', 'Asia/Kathmandu', 'Asia/Bangkok',
            'Asia/Jakarta', 'Asia/Singapore', 'Asia/Hong_Kong', 'Asia/Tokyo', 'Asia/Seoul',
            'Australia/Perth', 'Australia/Adelaide', 'Australia/Sydney',
            'Pacific/Auckland', 'Pacific/Fiji', 'Pacific/Honolulu',
            'UTC',
        ];
    }
}
