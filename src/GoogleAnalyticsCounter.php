<?php

namespace Drupal\ga_pageviews_counter;

use Drupal\google_analytics_reports_api\GoogleAnalyticsReportsApiFeed;
use Drupal\node\Entity\NodeType;
use Drupal\node\Entity\Node;
use Drupal\Core\Entity\EntityInterface;

/**
 * Google Analytics pageviews.
 */
class GoogleAnalyticsCounter {

  protected $api = NULL;
  protected $params = [];

  public function __construct() {
    $config = \Drupal::configFactory()->getEditable('google_analytics_reports_api.settings');
    $access_token = $access_token = $config->get('access_token');
    if ($access_token && time() < $config->get('expires_at')) {
      $this->api = new GoogleAnalyticsReportsApiFeed($access_token);
      $this->params = [
        'metrics'     => 'ga:pageviews',
        'dimensions'  => 'ga:pagePath',  
        'start_index' => 1,
        'start_date'  => mktime(0, 0, 0, 1, 1, date('Y')),
        'end_date'    => time(),
        'profile_id'  => 'ga:' . $config->get('profile_id'),
      ];
    }
  }

  public function fetchData() {
    if (!empty($this->api)) {

      $record_count = 0;
      $total_imported = 0;

      $this->truncateReports();

      do {
        $record_count = 0;      

        $feed = $this->api->queryReportFeed($this->params, ['refresh' => TRUE]);

        if (is_array($feed->results->rows)) {

          foreach ($feed->results->rows as $rows) {

            if (isset($rows['pagePath']) && isset($rows['pageviews'])) {
              $record_count++;            
              $pageviews = $rows['pageviews'];
              $source = \Drupal::service('path.alias_manager')->getPathByAlias($rows['pagePath']);

              if (preg_match('/node\/(\d+)/', $source, $matches)) {

                $types = NodeType::loadMultiple();
                $node = Node::load($matches[1]);

                db_merge('ga_pageviews_counter')
                  ->key([
                    'entity_id' => $node->id(),
                    'bundle'    => $node->bundle(),
                  ])
                  ->fields([
                    'pageviews' => (int) $pageviews,
                  ])
                  ->execute();
                $total_imported++;
              }
            }          
          }
        }

        if ($record_count > 0) {
          $this->params['start_index'] += 10000;
        }

      }
      while ($record_count > 0);
      \Drupal::logger('ga_pageviews_counter')->notice(t('Successfully checked @count items.', ['@count' => $total_imported]));
    }
  }

  public function getPageviews(EntityInterface $entity) {
    $count = db_select('ga_pageviews_counter', 'c')
      ->fields('c', ['pageviews'])
      ->condition('entity_id', $entity->id())
      ->condition('bundle', $entity->bundle())
      ->execute()
      ->fetchField();
    return number_format((int) $count);
  }

  public function deletePageviews(EntityInterface $entity) {
    db_delete('ga_pageviews_counter')
      ->condition('entity_id', $entity->id())
      ->condition('bundle', $entity->bundle())
      ->execute();
  }

  protected function truncateReports() {
    db_truncate('ga_pageviews_counter')
      ->execute();
  }

}
