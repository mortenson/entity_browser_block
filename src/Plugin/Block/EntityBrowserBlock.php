<?php

namespace Drupal\entity_browser_block\Plugin\Block;

use Drupal\Component\Utility\NestedArray;
use Drupal\Component\Utility\SortArray;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a generic entity browser block type.
 *
 * @Block(
 *  id = "entity_browser_block",
 *  admin_label = @Translation("Entity Browser Block"),
 *  category = @Translation("Entity Browser"),
 *  deriver = "Drupal\entity_browser_block\Plugin\Derivative\EntityBrowserBlockDeriver"
 * )
 */
class EntityBrowserBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity display repository service.
   *
   * @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface
   */
  protected $entityDisplayRepository;

  /**
   * The number of times this block allows rendering the same entity.
   *
   * @var int
   */
  const RECURSIVE_RENDER_LIMIT = 2;

  /**
   * An array of counters for the recursive rendering protection.
   *
   * @var array
   */
  protected static $recursiveRenderDepth = [];

  /**
   * Constructs a new EntityBrowserBlock.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Entity\EntityDisplayRepositoryInterface $entity_display_repository
   *   The entity display repository.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, EntityDisplayRepositoryInterface $entity_display_repository) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->entityDisplayRepository = $entity_display_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('entity_display.repository')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'entities' => [],
    ];
  }

  /**
   * Overrides \Drupal\Core\Block\BlockBase::blockForm().
   *
   * Adds body and description fields to the block configuration form.
   */
  public function blockForm($form, FormStateInterface $form_state) {
    if (!$form_state->has('first_load')) {
      $form_state->set('first_load', TRUE);
    }

    $form['selection'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'entity-browser-block-form'],
    ];

    $form['selection']['entity_browser'] = [
      '#type' => 'entity_browser',
      '#entity_browser' => $this->getDerivativeId(),
      '#process' => [
        [
          '\Drupal\entity_browser\Element\EntityBrowserElement',
          'processEntityBrowser'
        ],
        [self::class, 'processEntityBrowser'],
      ],
    ];

    $order_class = 'entity-browser-block-delta-order';

    $form['selection']['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Title'),
        $this->t('View mode'),
        $this->t('Operations'),
        $this->t('Order', [], ['context' => 'Sort order']),
      ],
      '#empty' => $this->t('No entities yet'),
      '#tabledrag' => [
        [
          'action' => 'order',
          'relationship' => 'sibling',
          'group' => $order_class,
        ],
      ],
      '#process' => [
        [self::class, 'processTable']
      ],
      '#block_configuration' => $this->configuration,
    ];

    return $form;
  }

  /**
   * Render API callback: Processes the table element.
   */
  public static function processTable(&$element, FormStateInterface $form_state, &$complete_form) {
    $parents = array_slice($element['#array_parents'], -3, 2);
    if ($form_state->get('first_load')) {
      $entities = self::loadEntitiesByIDs($element['#block_configuration']['entities']);
      $form_state->set('first_load', FALSE);
    }
    else {
      $selection = $form_state->getValue(array_merge($parents, ['table']), []);
      $entities = self::loadEntitiesByIDs(array_keys($selection));
    }

    $eb_entities = $form_state->getValue(array_merge($parents, ['entity_browser', 'entities']), []);
    foreach ($eb_entities as $entity) {
      $id = $entity->getEntityTypeId() . ':' . $entity->id();
      if (!isset($entities[$id])) {
        $entities[$id] = $entity;
      }
    }

    $view_mode_map = [];
    foreach ($element['#block_configuration']['entities'] as $id) {
      list($entity_type_id, $entity_id, $view_mode) = explode(':', $id);
      $view_mode_map[$entity_type_id . ':' . $entity_id] = $view_mode;
    }

    $display_repository = \Drupal::service('entity_display.repository');

    $delta = 0;

    foreach ($entities as $id => $entity) {
      $element[$id] = [
        '#attributes' => [
          'class' => ['draggable'],
          'data-entity-id' => $id,
        ],
        'title' => ['#markup' => $entity->label()],
        'view_mode' => [
          '#type' => 'select',
          '#options' => $display_repository->getViewModeOptions($entity->getEntityTypeId()),
        ],
        'operations' => [
          'remove' => [
            '#type' => 'button',
            '#value' => t('Remove'),
            '#op' => 'remove',
            '#name' => 'remove_' . $id,
            '#ajax' => [
              'callback' => [self::class, 'updateCallback'],
              'wrapper' => 'entity-browser-block-form',
            ],
          ],
        ],
        '_weight' => [
          '#type' => 'weight',
          '#title' => t('Weight for row @number', ['@number' => $delta + 1]),
          '#title_display' => 'invisible',
          '#delta' => count($entities),
          '#default_value' => $delta,
          '#attributes' => ['class' => ['entity-browser-block-delta-order']],
        ],
      ];
      if (isset($view_mode_map[$id])) {
        $element[$id]['view_mode']['#default_value'] = $view_mode_map[$id];
      }

      $delta++;
    }
    return $element;
  }

  /**
   * Loads entities based on an ID in the format entity_type:entity_id.
   *
   * @param array $ids
   *   An array of IDs.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   An array of loaded entities, keyed by an ID.
   */
  public static function loadEntitiesByIDs($ids) {
    $storages = [];
    $entities = [];
    foreach ($ids as $id) {
      list($entity_type_id, $entity_id) = explode(':', $id);
      if (!isset($storages[$entity_type_id])) {
        $storages[$entity_type_id] = \Drupal::entityTypeManager()->getStorage($entity_type_id);
      }
      $entities[$entity_type_id . ':' . $entity_id] = $storages[$entity_type_id]->load($entity_id);
    }
    return $entities;
  }

  /**
   * AJAX callback: Re-renders the Entity Browser button/table.
   */
  public static function updateCallback(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    if ($trigger['#op'] === 'remove') {
      $parents = array_slice($trigger['#array_parents'], 0, -4);
      $selection = NestedArray::getValue($form, $parents);
      $id = str_replace('remove_', '', $trigger['#name']);
      unset($selection['table'][$id]);
      $value = explode(' ', $selection['entity_browser']['entity_ids']['#value']);
      $selection['entity_browser']['entity_ids']['#value'] = array_diff($value, [$id]);
    }
    else {
      $parents = array_slice($trigger['#array_parents'], 0, -2);
      $selection = NestedArray::getValue($form, $parents);
    }
    return $selection;
  }

  /**
   * Render API callback: Processes the entity browser element.
   */
  public static function processEntityBrowser(&$element, FormStateInterface $form_state, &$complete_form) {
    $element['entity_ids']['#ajax'] = [
      'callback' => [self::class, 'updateCallback'],
      'wrapper' => 'entity-browser-block-form',
      'event' => 'entity_browser_value_updated',
    ];
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $selection = $form_state->getValue(['selection', 'table'], []);
    uasort($selection, function ($a, $b) {
      return SortArray::sortByKeyInt($a, $b, '_weight');
    });
    $entities = [];
    foreach ($selection as $id => $values) {
      $entities[] = $id . ':' . $values['view_mode'];
    }
    $this->configuration['entities'] = $entities;
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $build = [];
    $entity_helpers = [];

    foreach ($this->configuration['entities'] as $id) {
      list($entity_type_id, $entity_id, $view_mode) = explode(':', $id);
      if (!isset($entity_helpers[$entity_type_id])) {
        $entity_helpers[$entity_type_id] = [
          'storage' => $this->entityTypeManager->getStorage($entity_type_id),
          'view_builder' => $this->entityTypeManager->getViewBuilder($entity_type_id),
        ];
      }
      $entity = $entity_helpers[$entity_type_id]['storage']->load($entity_id);
      if ($entity && $entity->access('view')) {
        if (isset(static::$recursiveRenderDepth[$id])) {
          static::$recursiveRenderDepth[$id]++;
        }
        else {
          static::$recursiveRenderDepth[$id] = 1;
        }

        // Protect ourselves from recursive rendering.
        if (static::$recursiveRenderDepth[$id] > static::RECURSIVE_RENDER_LIMIT) {
          return $build;
        }

        $build[] = $entity_helpers[$entity_type_id]['view_builder']->view($entity, $view_mode);
      }
    }

    return $build;
  }

}
