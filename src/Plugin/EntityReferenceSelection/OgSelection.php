<?php

namespace Drupal\og\Plugin\EntityReferenceSelection;

use Drupal\Core\Entity\Plugin\EntityReferenceSelection\DefaultSelection;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\Entity\User;
use Drupal\og\Og;

/**
 * Provide default OG selection handler.
 *
 * Note that the id is correctly defined as "og:default" and not the other way
 * around, as seen in most other default selection handler (e.g. "default:node")
 * as OG's selection handler is a wrapper around those entity specific default
 * ones. That is, the same selection handler will be returned no matter what is
 * the target type of the reference field. Internally, it will call the original
 * selection handler, and use it for building the queries.
 *
 * @EntityReferenceSelection(
 *   id = "og:default",
 *   label = @Translation("OG selection"),
 *   group = "og",
 *   weight = 1
 * )
 */
class OgSelection extends DefaultSelection {

  /**
   * Get the selection handler of the field.
   *
   * @return DefaultSelection
   *   Returns the selection handler.
   */
  public function getSelectionHandler() {
    $options = [
      'target_type' => $this->configuration['target_type'],
      // 'handler' key intentionally absent as we want the selection manager to
      // choose the best option.
      // @see \Drupal\Core\Entity\EntityReferenceSelection\SelectionPluginManager::getInstance()
      'handler_settings' => $this->configuration['handler_settings'],
    ];
    return \Drupal::service('plugin.manager.entity_reference_selection')->getInstance($options);
  }

  /**
   * Overrides ::buildEntityQuery.
   *
   * Return only group in the matching results.
   *
   * @param string|null $match
   *   (Optional) Text to match the label against. Defaults to NULL.
   * @param string $match_operator
   *   (Optional) The operation the matching should be done with. Defaults
   *   to "CONTAINS".
   *
   * @return \Drupal\Core\Entity\Query\QueryInterface
   *   The EntityQuery object with the basic conditions and sorting applied to
   *   it.
   */
  protected function buildEntityQuery($match = NULL, $match_operator = 'CONTAINS') {
    // Getting the original entity selection handler. OG selection handler using
    // the default selection handler of the entity, which the field reference
    // to, and add another logic to the query object i.e. check if the entities
    // bundle defined as group.
    $query = $this->getSelectionHandler()->buildEntityQuery($match, $match_operator);
    $target_type = $this->configuration['target_type'];
    $definition = \Drupal::entityTypeManager()->getDefinition($target_type);

    if ($bundle_key = $definition->getKey('bundle')) {
      $bundles = Og::groupTypeManager()->getAllGroupBundles($target_type);

      if (!$bundles) {
        // If there are no bundles defined, we can return early.
        return $query;
      }
      $query->condition($bundle_key, $bundles, 'IN');
    }

    $user = User::load(\Drupal::currentUser()->id());
    if ($user->hasPermission('administer group')) {
      // User can see all the groups.
      return $query;
    }

    // User is a non-site wide group admin.
    $identifier_key = $definition->getKey('id');

    /** @var \Drupal\og\OgAccessInterface $og_access */
    $og_access = \Drupal::service('og.access');

    if (empty($this->configuration['entity'])) {
      // @todo: Find out why we have this scenario.
      return $query;
    }

    /** @var \Drupal\Core\Entity\EntityInterface $entity */
    $entity = $this->configuration['entity'];
    $entity_type_id = $entity->getEntityTypeId();
    $bundle = $entity->bundle();

    $ids = [];
    foreach ($this->getUserGroups() as $group) {
      // Check user has "create" permission on this entity.
      if ($og_access->userAccess($group, "create $entity_type_id $bundle", $user)->isAllowed()) {
        $ids[] = $group->id();
      }
    }

    if ($ids) {
      $query->condition($identifier_key, $ids, 'IN');
    }
    else {
      // User doesn't have permission to select any group so falsify this
      // query.
      $query->condition($identifier_key, -1);
    }

    return $query;
  }

  /**
   * Return all the user's groups.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface[]
   *   Array with the user's group, or an empty array if none found.
   */
  protected function getUserGroups() {
    $user = User::load($this->currentUser->id());
    /** @var \Drupal\og\MembershipManagerInterface $membership_manager */
    $membership_manager = \Drupal::service('og.membership_manager');
    $other_groups = $membership_manager->getUserGroups($user);
    return isset($other_groups[$this->configuration['target_type']]) ? $other_groups[$this->configuration['target_type']] : [];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    // Filter out the bundles that are not groups.
    $entity_type_id = $this->configuration['target_type'];
    $entity_type = $this->entityManager->getDefinition($entity_type_id);
    $bundles_info = $this->entityManager->getBundleInfo($entity_type_id);

    if ($entity_type->hasKey('bundle')) {

      foreach (Og::groupTypeManager()->getAllGroupBundles($entity_type_id) as $bundle) {
        $bundle_options[$bundle] = $bundles_info[$bundle]['label'];
      }

      natsort($bundle_options);
      $form['target_bundles']['#options'] = $bundle_options;
    }

    return $form;
  }

}
