<?php

namespace App\Enums;

/**
 * An optional refinement of inside or outside. Context stays the dimension every
 * stat stratifies on; the surface type only narrows it, so each type belongs to
 * exactly one context and can never contradict it.
 */
enum SurfaceType: string
{
    case Mat = 'mat';
    case Carpet = 'carpet';
    case PracticeGreen = 'practice_green';
    case CourseGreen = 'course_green';
    case Turf = 'turf';

    public function label(): string
    {
        return match ($this) {
            self::Mat => 'Putting mat',
            self::Carpet => 'Carpet',
            self::PracticeGreen => 'Practice green',
            self::CourseGreen => 'Course green',
            self::Turf => 'Artificial turf',
        };
    }

    public function context(): PuttContext
    {
        return match ($this) {
            self::Mat, self::Carpet => PuttContext::Inside,
            self::PracticeGreen, self::CourseGreen, self::Turf => PuttContext::Outside,
        };
    }

    /**
     * @return array<int, self>
     */
    public static function forContext(PuttContext $context): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $type): bool => $type->context() === $context,
        ));
    }
}
