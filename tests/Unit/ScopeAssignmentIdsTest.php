<?php
declare(strict_types=1);

use MiniERP\Security\ScopeAssignmentIds;
use PHPUnit\Framework\TestCase;

final class ScopeAssignmentIdsTest extends TestCase
{
    public function testParsesCommaSeparatedFormInputAndRemovesDuplicates(): void
    {
        self::assertSame([12, 34], ScopeAssignmentIds::parseInput(' 12, 34,12 '));
        self::assertSame([], ScopeAssignmentIds::parseInput('  '));
        self::assertSame([7], ScopeAssignmentIds::parseInput(7));
    }

    public function testNormalizesWordPressArrayMetaForBothActorAndAccessForm(): void
    {
        $stored = [12, '34', 12];

        self::assertSame([12, 34], ScopeAssignmentIds::normalizeStored($stored));
        self::assertSame([12, 34], ScopeAssignmentIds::parseInput('12,34'));
        self::assertSame([], ScopeAssignmentIds::normalizeStored([]));
    }

    public function testParsesNativeMultiSelectAssignments(): void
    {
        self::assertSame([12, 34], ScopeAssignmentIds::parseSelection(['12', '34', '12']));
        self::assertSame([], ScopeAssignmentIds::parseSelection([]));
        self::assertSame([7], ScopeAssignmentIds::parseSelection('7, 7'));
    }

    public function testRejectsMalformedOrOverlargeAssignmentsFailClosed(): void
    {
        self::assertNull(ScopeAssignmentIds::parseInput('1,,2'));
        self::assertNull(ScopeAssignmentIds::parseInput('01,2'));
        self::assertNull(ScopeAssignmentIds::parseInput('0'));
        self::assertNull(ScopeAssignmentIds::parseInput((string) PHP_INT_MAX . '0'));
        self::assertNull(ScopeAssignmentIds::parseInput(implode(',', array_fill(0, 101, '1'))));
        self::assertNull(ScopeAssignmentIds::normalizeStored([1, 'invalid']));
        self::assertNull(ScopeAssignmentIds::normalizeStored([2 => 1]));
        self::assertNull(ScopeAssignmentIds::normalizeStored(array_fill(0, 101, 1)));
        self::assertNull(ScopeAssignmentIds::parseSelection(['1', '', '2']));
        self::assertNull(ScopeAssignmentIds::parseSelection('01,2'));
        self::assertNull(ScopeAssignmentIds::parseSelection('0'));
        self::assertNull(ScopeAssignmentIds::parseSelection(['1', '92233720368547758080']));
        self::assertNull(ScopeAssignmentIds::parseSelection(array_fill(0, 101, '1')));
    }
}
