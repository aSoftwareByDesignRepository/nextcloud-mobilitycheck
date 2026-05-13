<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Tests\Unit\Util;

use OCA\MobilityCheck\Service\GdprErasureService;
use OCA\MobilityCheck\Util\GdprJsonPseudonymizer;
use PHPUnit\Framework\TestCase;

final class GdprJsonPseudonymizerTest extends TestCase
{
	public function testScrubJsonStringUidListRemovesLeaver(): void
	{
		$in = json_encode(['alice', 'bob', 'carol'], JSON_THROW_ON_ERROR);
		[$changed, $out] = GdprJsonPseudonymizer::scrubJsonStringUidList($in, 'bob');
		$this->assertTrue($changed);
		$this->assertSame('["alice","carol"]', $out);
	}

	public function testScrubJsonStringUidListAllRemovedBecomesNull(): void
	{
		$in = json_encode(['only'], JSON_THROW_ON_ERROR);
		[$changed, $out] = GdprJsonPseudonymizer::scrubJsonStringUidList($in, 'only');
		$this->assertTrue($changed);
		$this->assertNull($out);
	}

	public function testScrubJsonStringUidListUnchangedWhenAbsent(): void
	{
		$in = json_encode(['x'], JSON_THROW_ON_ERROR);
		[$changed, $out] = GdprJsonPseudonymizer::scrubJsonStringUidList($in, 'y');
		$this->assertFalse($changed);
		$this->assertSame($in, $out);
	}

	public function testScrubJsonStringUidListLeavesMalformedUntouched(): void
	{
		$in = '{"not":"a list"}';
		[$changed, $out] = GdprJsonPseudonymizer::scrubJsonStringUidList($in, 'x');
		$this->assertFalse($changed);
		$this->assertSame($in, $out);
	}

	public function testScrubNestedUidStringsReplacesExactStrings(): void
	{
		$in = json_encode([
			'chainId' => 'c1',
			'steps' => [
				['step_id' => 'lm', 'approver' => ['kind' => 'role', 'hint' => 'alice']],
			],
		], JSON_THROW_ON_ERROR);
		[$changed, $out] = GdprJsonPseudonymizer::scrubNestedUidStrings($in, 'alice', GdprErasureService::TOMBSTONE);
		$this->assertTrue($changed);
		$this->assertStringContainsString(GdprErasureService::TOMBSTONE, (string)$out);
		$this->assertStringNotContainsString('alice', (string)$out);
	}

	public function testScrubNestedUidStringsUnchanged(): void
	{
		$in = '{"a":"b"}';
		[$changed, $out] = GdprJsonPseudonymizer::scrubNestedUidStrings($in, 'alice', GdprErasureService::TOMBSTONE);
		$this->assertFalse($changed);
		$this->assertSame($in, $out);
	}
}
