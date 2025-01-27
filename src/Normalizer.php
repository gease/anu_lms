<?php

namespace Drupal\anu_lms;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Language\LanguageManager;
use Symfony\Component\Serializer\Serializer;
use Drupal\serialization\Normalizer\CacheableNormalizerInterface;

/**
 * Normalizer service for the given entities.
 */
class Normalizer {

  /**
   * The entity repository service.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;

  /**
   * The serializer.
   *
   * @var \Symfony\Component\Serializer\Serializer
   */
  protected $serializer;

  /**
   * The cache.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * Language manager.
   *
   * @var \Drupal\Core\Language\LanguageManager
   */
  protected $languageManager;

  /**
   * Default max depth.
   *
   * @var int
   */
  protected $defaultMaxDepth = 10;

  /**
   * Constructs service.
   *
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   * @param \Symfony\Component\Serializer\Serializer $serializer
   *   The serializer.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache.
   * @param \Drupal\Core\Language\LanguageManager $language_manager
   *   Language manager.
   */
  public function __construct(EntityRepositoryInterface $entity_repository, Serializer $serializer, CacheBackendInterface $cache, LanguageManager $language_manager) {
    $this->entityRepository = $entity_repository;
    $this->serializer = $serializer;
    $this->cache = $cache;
    $this->languageManager = $language_manager;
  }

  /**
   * Helper to generate cache id for custom caching.
   */
  protected function getCacheId($cache_name) {
    $lang = $this->languageManager->getCurrentLanguage()->getId();
    return "anu_lms:$cache_name:$lang";
  }

  /**
   * Normalizes given entities with `json_recursive` normalizer.
   *
   * Load nested entities recursively with configuration from context variable.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   An array of entities.
   * @param array $context
   *   An array with settings for normalizer.
   *
   * @return array
   *   An array of normalized entities.
   */
  public function normalizeEntity(EntityInterface $entity, array $context = []) {
    // Double check entity access.
    if (!$entity->access('view')) {
      return NULL;
    }

    // We are going to cache only normalized data of root entities
    // but even in this case same entities may be requested with
    // different max depth.
    $max_depth = empty($context['max_depth']) ? $this->defaultMaxDepth : $context['max_depth'];
    $cache_cid = $this->getCacheId($entity->getEntityTypeId() . ':' . $entity->id() . ':' . $max_depth);
    // Trying to get cached results first.
    if ($cache = $this->cache->get($cache_cid)) {
      return $cache->data;
    }

    $cacheable_key = CacheableNormalizerInterface::SERIALIZATION_CONTEXT_CACHEABILITY;

    // Default configurations.
    $default_context = [
      // Let rest_entity_recursive collect all cacheable metadata.
      $cacheable_key => new CacheableMetadata(),
      'settings' => [
        'user' => [
          'exclude_fields' => [
            'access', 'login', 'init', 'mail', 'roles', 'created', 'changed', 'preferred_admin_langcode', 'timezone', 'default_langcode', 'role_change',
          ],
        ],
        'node' => [
          'exclude_fields' => [
            'uid', 'type', 'vid', 'revision_timestamp', 'revision_uid', 'revision_log', 'revision_translation_affected', 'promote', 'sticky', 'menu_link', 'default_langcode', 'langcode', 'content_translation_source', 'content_translation_outdated', 'field_course_modules', 'field_module_lesson_module', '	field_module_assessment_module',
          ],
        ],
        'paragraph' => [
          'exclude_fields' => [
            'revision_id', 'revision_translation_affected', 'created', 'parent_id', 'parent_type', 'parent_field_name', 'behavior_settings', 'default_langcode', 'langcode', 'content_translation_source', 'content_translation_outdated', 'content_translation_changed',
          ],
        ],
        'taxonomy_term' => [
          'exclude_fields' => [
            'revision_created', 'revision_user', 'revision_log_message', 'description', 'revision_translation_affected', 'path', 'default_langcode', 'langcode', 'content_translation_source', 'content_translation_outdated', 'content_translation_uid', 'content_translation_created',
          ],
        ],
      ],
    ];
    // Merge default and given context settings.
    $context = array_merge_recursive($default_context, $context);

    // Get translated version of the entity.
    $entity = $this->entityRepository->getTranslationFromContext($entity);

    // Normalize recursively given entity.
    $normalized_entity = $this->serializer->normalize($entity, 'json_recursive', $context);

    /** @var \Drupal\Core\Cache\CacheableMetadata $cacheable_metadata */
    $cacheable_metadata = $context[$cacheable_key];
    // Saving normalized entity to the cache for further usage.
    $this->cache->set(
      $cache_cid,
      $normalized_entity,
      $cacheable_metadata->getCacheMaxAge(),
      $cacheable_metadata->getCacheTags(),
    );

    return $normalized_entity;
  }

}
