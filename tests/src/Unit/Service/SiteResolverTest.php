<?php

namespace Drupal\Tests\quantsearch_ai\Unit\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\quantsearch_ai\Service\SiteResolver;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\quantsearch_ai\Service\SiteResolver
 * @group quantsearch_ai
 */
class SiteResolverTest extends UnitTestCase {

  protected function buildResolver(array $settings): SiteResolver {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(fn(string $key) => $this->dotGet($settings, $key));

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('quantsearch_ai.settings')->willReturn($config);

    return new SiteResolver($factory);
  }

  protected function dotGet(array $arr, string $path) {
    foreach (explode('.', $path) as $key) {
      if (!is_array($arr) || !array_key_exists($key, $arr)) {
        return NULL;
      }
      $arr = $arr[$key];
    }
    return $arr;
  }

  public function testFlatConfigFallback(): void {
    $resolver = $this->buildResolver([
      'site_id' => 'flat-site',
      'base_url' => 'https://flat.example',
      'api_endpoint' => 'https://api.example',
    ]);

    $this->assertSame('flat-site', $resolver->getSiteId(NULL));
    $this->assertSame('flat-site', $resolver->getSiteId('en'));
    $this->assertSame('https://flat.example', $resolver->getSiteBaseUrl('en'));
  }

  public function testLanguageMapResolvesByLangcode(): void {
    $resolver = $this->buildResolver([
      'site_id' => 'flat-site',
      'language_sites' => [
        'en' => ['site_id' => 'en-site', 'base_url' => 'https://en.example'],
        'fr' => ['site_id' => 'fr-site', 'base_url' => 'https://fr.example'],
      ],
    ]);

    $this->assertSame('en-site', $resolver->getSiteId('en'));
    $this->assertSame('fr-site', $resolver->getSiteId('fr'));
    $this->assertSame('https://fr.example', $resolver->getSiteBaseUrl('fr'));
  }

  public function testUnmappedLanguageFallsBackToFlat(): void {
    $resolver = $this->buildResolver([
      'site_id' => 'flat-site',
      'language_sites' => [
        'en' => ['site_id' => 'en-site', 'base_url' => 'https://en.example'],
      ],
    ]);

    $this->assertSame('flat-site', $resolver->getSiteId('de'));
  }

  public function testIsMultilingualReflectsMap(): void {
    $resolver = $this->buildResolver(['site_id' => 'flat-site']);
    $this->assertFalse($resolver->isMultilingual());

    $resolver = $this->buildResolver([
      'site_id' => 'flat-site',
      'language_sites' => ['en' => ['site_id' => 'en-site']],
    ]);
    $this->assertTrue($resolver->isMultilingual());
  }

  public function testGetMappedLanguagesReturnsConfiguredLangcodes(): void {
    $resolver = $this->buildResolver([
      'language_sites' => [
        'en' => ['site_id' => 'en-site'],
        'fr' => ['site_id' => 'fr-site'],
      ],
    ]);

    $this->assertEqualsCanonicalizing(['en', 'fr'], $resolver->getMappedLanguages());
  }

  public function testApiEndpointDefaultsWhenUnset(): void {
    $resolver = $this->buildResolver([]);
    $this->assertSame('https://quantsearch.ai/api', $resolver->getApiEndpoint());
  }

  public function testApiEndpointReturnsConfiguredValue(): void {
    $resolver = $this->buildResolver(['api_endpoint' => 'https://staging.example/api']);
    $this->assertSame('https://staging.example/api', $resolver->getApiEndpoint());
  }

  public function testNullLangcodeFallsBackToFlatEvenWhenMultilingual(): void {
    $resolver = $this->buildResolver([
      'site_id' => 'flat-site',
      'language_sites' => [
        'en' => ['site_id' => 'en-site'],
        'fr' => ['site_id' => 'fr-site'],
      ],
    ]);

    $this->assertSame('flat-site', $resolver->getSiteId(NULL));
  }

  public function testEmptyLangcodeFallsBackToFlat(): void {
    $resolver = $this->buildResolver([
      'site_id' => 'flat-site',
      'language_sites' => [
        'en' => ['site_id' => 'en-site'],
      ],
    ]);

    $this->assertSame('flat-site', $resolver->getSiteId(''));
  }
}
