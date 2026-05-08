<?php

namespace Drupal\quantsearch_ai\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Resolves which QuantSearch site to target for a given language.
 *
 * Single source of truth so callers don't repeat the
 * "language_sites map vs flat config fallback" branch.
 */
class SiteResolver {

  protected ConfigFactoryInterface $configFactory;

  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * The site_id for a given langcode, or the flat fallback if unmapped.
   */
  public function getSiteId(?string $langcode): ?string {
    return $this->lookup($langcode, 'site_id');
  }

  /**
   * The site base_url for a given langcode (Drupal-side public URL prefix).
   */
  public function getSiteBaseUrl(?string $langcode): ?string {
    return $this->lookup($langcode, 'base_url');
  }

  /**
   * The API endpoint (shared across all sites — comes from flat config).
   */
  public function getApiEndpoint(): string {
    $config = $this->configFactory->get('quantsearch_ai.settings');
    return $config->get('api_endpoint') ?: 'https://quantsearch.ai/api';
  }

  /**
   * TRUE if a language_sites map is configured.
   */
  public function isMultilingual(): bool {
    return !empty($this->configFactory->get('quantsearch_ai.settings')->get('language_sites'));
  }

  /**
   * Langcodes explicitly mapped to a QuantSearch site.
   *
   * @return string[]
   */
  public function getMappedLanguages(): array {
    $map = $this->configFactory->get('quantsearch_ai.settings')->get('language_sites') ?: [];
    return array_keys($map);
  }

  protected function lookup(?string $langcode, string $key): ?string {
    $config = $this->configFactory->get('quantsearch_ai.settings');
    if ($langcode !== NULL && $langcode !== '') {
      $value = $config->get("language_sites.$langcode.$key");
      if (!empty($value)) {
        return $value;
      }
    }
    return $config->get($key);
  }
}
