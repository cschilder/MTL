<?php

declare(strict_types=1);

namespace MTL\Tests\Unit;

use MTL\Support\Validator;
use MTL\Tests\TestCase;

defined('MTL_APP') || exit;

/**
 * Input validation.
 *
 * Two things are being checked. That the rules accept and refuse what they say
 * they do, and — more importantly — that `validated()` returns *coerced* values:
 * everything arriving from a form is a string, and a controller that writes
 * "42" into an integer column or the string "0" into a boolean one is how bad
 * data gets in.
 */
final class ValidatorTest extends TestCase
{
    public function testPassingDataIsReturnedCoerced(): void
    {
        $validator = Validator::make(
            ['title' => 'IJsland', 'position' => '3', 'published' => 'on', 'rating' => '4.5'],
            ['title' => 'required|string', 'position' => 'required|int', 'published' => 'boolean', 'rating' => 'numeric']
        );

        $this->assertTrue($validator->passes());

        $clean = $validator->validated();

        $this->assertSame('IJsland', $clean['title']);
        $this->assertSame(3, $clean['position'], 'an integer field must not stay a string');
        $this->assertSame(true, $clean['published'], 'a boolean field must not stay the string "on"');
        $this->assertSame(4.5, $clean['rating']);
    }

    public function testRequiredRejectsMissingAndEmptyValues(): void
    {
        foreach ([[], ['title' => ''], ['title' => '   '], ['title' => null]] as $data) {
            $validator = Validator::make($data, ['title' => 'required|string']);

            $this->assertFalse($validator->passes(), 'should have failed for ' . json_encode($data));
            $this->assertNotEmpty($validator->errors()['title'] ?? null);
        }
    }

    /**
     * `nullable` and `required` are different: an optional field that was left
     * blank has to come through as null rather than as the empty string, or an
     * unset date lands in the column as "0000-00-00".
     */
    public function testNullableFieldsCanBeBlank(): void
    {
        $validator = Validator::make(['summary' => ''], ['summary' => 'nullable|string|max:500']);

        $this->assertTrue($validator->passes());

        $clean = $validator->validated();

        $this->assertTrue(array_key_exists('summary', $clean), 'the field should be present');
        $this->assertNull($clean['summary']);
    }

    /**
     * `sometimes` is what makes a partial update possible: a field that was not
     * submitted must be absent from the result, not present as null, or every
     * edit form would blank out the fields it does not show.
     */
    public function testSometimesLeavesAnAbsentFieldOutAltogether(): void
    {
        $validator = Validator::make(['title' => 'IJsland'], [
            'title'   => 'required|string',
            'summary' => 'sometimes|string',
        ]);

        $this->assertTrue($validator->passes());
        $this->assertFalse(array_key_exists('summary', $validator->validated()));
    }

    public function testEmailRule(): void
    {
        foreach (['reiziger@example.com', 'a.b+tag@sub.example.co.uk'] as $email) {
            $this->assertTrue(Validator::make(['e' => $email], ['e' => 'required|email'])->passes(), $email);
        }

        foreach (['', 'reiziger', 'reiziger@', '@example.com', 'a b@example.com', 'a@b'] as $email) {
            $this->assertFalse(
                Validator::make(['e' => $email], ['e' => 'required|email'])->passes(),
                'should have failed: ' . json_encode($email)
            );
        }
    }

    public function testSlugRule(): void
    {
        foreach (['ijsland', 'ijsland-in-de-winter', 'stop-2'] as $slug) {
            $this->assertTrue(Validator::make(['s' => $slug], ['s' => 'required|slug'])->passes(), $slug);
        }

        foreach (['IJsland', 'ijsland_winter', '-ijsland', 'ijsland-', 'ijsland--winter', 'ijsland winter', 'ijslând'] as $slug) {
            $this->assertFalse(
                Validator::make(['s' => $slug], ['s' => 'required|slug'])->passes(),
                'should have failed: ' . $slug
            );
        }
    }

    /** Length for text, magnitude for numbers, count for arrays. */
    public function testMinAndMaxApplyToTheRightProperty(): void
    {
        $this->assertTrue(Validator::make(['t' => 'abcd'], ['t' => 'string|min:4|max:4'])->passes());
        $this->assertFalse(Validator::make(['t' => 'abc'], ['t' => 'string|min:4'])->passes());
        $this->assertFalse(Validator::make(['t' => 'abcde'], ['t' => 'string|max:4'])->passes());

        $this->assertTrue(Validator::make(['n' => '5'], ['n' => 'int|min:5|max:5'])->passes());
        $this->assertFalse(Validator::make(['n' => '4'], ['n' => 'int|min:5'])->passes());
        $this->assertFalse(Validator::make(['n' => '6'], ['n' => 'int|max:5'])->passes());

        $this->assertTrue(Validator::make(['a' => [1, 2]], ['a' => 'array|min:2|max:2'])->passes());
        $this->assertFalse(Validator::make(['a' => [1]], ['a' => 'array|min:2'])->passes());
        $this->assertFalse(Validator::make(['a' => [1, 2, 3]], ['a' => 'array|max:2'])->passes());
    }

    /** A multi-byte string is measured in characters, not bytes. */
    public function testLengthCountsCharactersNotBytes(): void
    {
        // Six characters, nine bytes in UTF-8.
        $this->assertTrue(Validator::make(['t' => 'Þingve'], ['t' => 'string|max:6'])->passes());
    }

    public function testInRule(): void
    {
        $rules = ['status' => 'required|in:draft,published,archived'];

        $this->assertTrue(Validator::make(['status' => 'draft'], $rules)->passes());
        $this->assertFalse(Validator::make(['status' => 'Draft'], $rules)->passes());
        $this->assertFalse(Validator::make(['status' => 'deleted'], $rules)->passes());
    }

    public function testBooleanAcceptsTheFormsAFormActuallySends(): void
    {
        foreach (['1' => true, 'on' => true, 'true' => true, 'yes' => true,
                  '0' => false, 'off' => false, 'false' => false, 'no' => false] as $input => $expected) {
            $validator = Validator::make(['b' => (string) $input], ['b' => 'boolean']);

            $this->assertTrue($validator->passes(), 'should accept ' . json_encode($input));
            $this->assertSame($expected, $validator->validated()['b'], 'wrong value for ' . json_encode($input));
        }

        $this->assertFalse(Validator::make(['b' => 'maybe'], ['b' => 'boolean'])->passes());
    }

    /**
     * A boolean that is false has to arrive as false.
     *
     * When a rule signalled failure by returning `false`, a value of false was
     * indistinguishable from a rejected one: the field was dropped from the
     * validated data with no error recorded, so `passes()` was true and the
     * caller then fell back to the stored value. Unchecking a box and saving
     * left the setting on, with nothing to show why.
     */
    public function testAFalseBooleanIsKeptRatherThanDropped(): void
    {
        foreach (['0', 'off', 'false', 'no'] as $input) {
            $validator = Validator::make(['published' => $input], ['published' => 'boolean']);

            $this->assertTrue($validator->passes(), 'should be valid: ' . $input);

            $clean = $validator->validated();

            $this->assertTrue(
                array_key_exists('published', $clean),
                'the field disappeared for input ' . json_encode($input)
            );
            $this->assertSame(false, $clean['published'], 'wrong value for ' . json_encode($input));
        }
    }

    /**
     * The same collision on the other side: an integer of 0 and a float of 0.0
     * are ordinary values, not failures.
     */
    public function testZeroValuesAreKept(): void
    {
        $clean = Validator::make(
            ['position' => '0', 'altitude' => '0', 'latitude' => '0'],
            ['position' => 'int', 'altitude' => 'numeric', 'latitude' => 'latitude']
        )->validated();

        $this->assertSame(0, $clean['position']);
        $this->assertSame(0.0, $clean['altitude']);
        $this->assertSame(0.0, $clean['latitude']);
    }

    public function testLatitudeAndLongitudeBounds(): void
    {
        $this->assertTrue(Validator::make(['lat' => '63.985'], ['lat' => 'latitude'])->passes());
        $this->assertTrue(Validator::make(['lat' => '-90'], ['lat' => 'latitude'])->passes());
        $this->assertTrue(Validator::make(['lat' => '90'], ['lat' => 'latitude'])->passes());
        $this->assertFalse(Validator::make(['lat' => '90.1'], ['lat' => 'latitude'])->passes());
        $this->assertFalse(Validator::make(['lat' => '-90.1'], ['lat' => 'latitude'])->passes());

        $this->assertTrue(Validator::make(['lon' => '-22.605'], ['lon' => 'longitude'])->passes());
        $this->assertTrue(Validator::make(['lon' => '180'], ['lon' => 'longitude'])->passes());
        $this->assertFalse(Validator::make(['lon' => '180.1'], ['lon' => 'longitude'])->passes());
    }

    public function testConfirmedComparesAgainstTheConfirmationField(): void
    {
        $this->assertTrue(Validator::make(
            ['password' => 'reis-door-de-wereld', 'password_confirmation' => 'reis-door-de-wereld'],
            ['password' => 'required|confirmed']
        )->passes());

        $this->assertFalse(Validator::make(
            ['password' => 'reis-door-de-wereld', 'password_confirmation' => 'reis-door-de-werld'],
            ['password' => 'required|confirmed']
        )->passes());

        // A missing confirmation must fail rather than compare equal to nothing.
        $this->assertFalse(Validator::make(
            ['password' => 'reis-door-de-wereld'],
            ['password' => 'required|confirmed']
        )->passes());
    }

    public function testTimezoneRule(): void
    {
        $this->assertTrue(Validator::make(['tz' => 'Europe/Amsterdam'], ['tz' => 'timezone'])->passes());
        $this->assertTrue(Validator::make(['tz' => 'Atlantic/Reykjavik'], ['tz' => 'timezone'])->passes());
        $this->assertFalse(Validator::make(['tz' => 'Europe/Atlantis'], ['tz' => 'timezone'])->passes());
    }

    public function testEveryFailingFieldIsReported(): void
    {
        $validator = Validator::make(
            ['title' => '', 'email' => 'nope', 'slug' => 'Not A Slug'],
            ['title' => 'required|string', 'email' => 'required|email', 'slug' => 'required|slug']
        );

        $this->assertFalse($validator->passes());
        // All three, not just the first: a form that reports one error at a time
        // takes three round trips to fill in.
        $this->assertCount(3, $validator->errors());
    }

    public function testUnlistedFieldsAreDropped(): void
    {
        $validator = Validator::make(
            ['title' => 'IJsland', 'role' => 'admin'],
            ['title' => 'required|string']
        );

        $this->assertTrue($validator->passes());
        // Mass assignment is the reason: whatever the form posted, only the
        // fields with rules come out.
        $this->assertFalse(array_key_exists('role', $validator->validated()));
    }
}
