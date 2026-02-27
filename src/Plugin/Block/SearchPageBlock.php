<?php

namespace Drupal\quantsearch_ai\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a QuantSearch full Search Page block.
 *
 * @Block(
 *   id = "quantsearch_search_page",
 *   admin_label = @Translation("QuantSearch Search Page"),
 *   category = @Translation("QuantSearch")
 * )
 */
class SearchPageBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'theme' => 'auto',
      'color' => '#10b981',
      'show_ai_answer' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form['theme'] = [
      '#type' => 'select',
      '#title' => $this->t('Theme'),
      '#options' => [
        'auto' => $this->t('Auto (follows system)'),
        'light' => $this->t('Light'),
        'dark' => $this->t('Dark'),
      ],
      '#default_value' => $this->configuration['theme'],
    ];

    $form['color'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Accent Color'),
      '#default_value' => $this->configuration['color'],
      '#description' => $this->t('Hex color code (e.g., #10b981)'),
    ];

    $form['show_ai_answer'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show AI answer'),
      '#default_value' => $this->configuration['show_ai_answer'],
      '#description' => $this->t('Display an AI-generated answer above search results.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $this->configuration['theme'] = $form_state->getValue('theme');
    $this->configuration['color'] = $form_state->getValue('color');
    $this->configuration['show_ai_answer'] = $form_state->getValue('show_ai_answer');
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $config = \Drupal::config('quantsearch_ai.settings');
    $site_id = $config->get('site_id');
    $cdn_url = $config->get('cdn_url') ?: 'https://cdn.quantsearch.ai/v1';

    if (!$site_id) {
      return [
        '#markup' => $this->t('QuantSearch not configured.'),
        '#cache' => ['max-age' => 0],
      ];
    }

    return [
      '#theme' => 'quantsearch_search_page',
      '#site_id' => $site_id,
      '#cdn_url' => $cdn_url,
      '#theme_setting' => $this->configuration['theme'],
      '#color' => $this->configuration['color'],
      '#show_ai_answer' => $this->configuration['show_ai_answer'],
      '#cache' => [
        'tags' => ['config:quantsearch_ai.settings'],
      ],
    ];
  }

}
