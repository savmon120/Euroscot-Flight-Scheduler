<?php

namespace Drupal\flight_scheduler\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

class FlightEntryForm extends FormBase {

  public function getFormId() {
    return 'flight_scheduler_entry_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#tree'] = TRUE;
    $form['#attached']['library'][] = 'flight_scheduler/flight_form_styles';

    $outbound = $form_state->getValue('outbound') ?? [];
    $return = $form_state->getValue('return') ?? [];

    $dep_icao = strtoupper(trim($outbound['dep_icao'] ?? ''));
    $arr_icao = strtoupper(trim($outbound['arr_icao'] ?? ''));
    $dep_time_raw = trim($outbound['dep_time'] ?? '');

    // Selected aircraft code (default A320)
    $aircraft_code = $outbound['aircraft'] ?? 'default';
    $aircrafts = $this->getAircraftOptions();
    $cruise_speed = $aircrafts[$aircraft_code]['speed'] ?? null;

    // Base calculations (great circle)
    $distance = $dep_icao && $arr_icao ? $this->calculateDistance($dep_icao, $arr_icao) : null;
    $duration = $distance ? $this->calculateFlightLength($distance, $aircraft_code) : null;

    // Buffers (dispatcher-grade realism)
    $buffer_nm = 30;     // 15 NM for SID + 15 NM for STAR
    $taxi_minutes = 10;  // total taxi in + out

    // Derived values
    $total_distance = $distance ? $distance + $buffer_nm : null;       // display-only
    $airborne_duration = $duration;                                     // minutes (raw distance)
    $block_duration = $duration ? $duration + $taxi_minutes : null;     // minutes (airborne + taxi)

    $form['#prefix'] = '<div id="flight-entry-form-wrapper">';
    $form['#suffix'] = '</div>';

    // Outbound Panel
    $form['outbound'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Flight information'),
      '#prefix' => '<div class="dispatch-left">',
      '#suffix' => '</div>',
    ];

    $form['outbound']['dep_icao'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Departure ICAO'),
      '#required' => TRUE,
      '#default_value' => $dep_icao,
      '#ajax' => [
        'callback' => [$this, 'rebuildCallback'],
        'event' => 'change',
        'wrapper' => 'flight-entry-form-wrapper',
      ],
    ];

    $form['outbound']['arr_icao'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Arrival ICAO'),
      '#required' => TRUE,
      '#default_value' => $arr_icao,
      '#ajax' => [
        'callback' => [$this, 'rebuildCallback'],
        'event' => 'change',
        'wrapper' => 'flight-entry-form-wrapper',
      ],
    ];

    /*$form['outbound']['callsign'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Callsign'),
    //  '#default_value' => $outbound['callsign'],// ?? 'SCO15AB',
    ];*/

    /*$form['outbound']['flight_number'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Flight Number'),
    //  '#default_value' => $outbound['flight_number'],// ?? 'S6AB',
    ];*/

    // Aircraft dropdown
    $aircraft_options = [];
    foreach ($aircrafts as $key => $info) {
      $aircraft_options[$key] = $info['label'];
    }

    $form['outbound']['aircraft'] = [
      '#type' => 'select',
      '#title' => $this->t('Aircraft'),
      '#options' => $aircraft_options,
      '#default_value' => $aircraft_code,
      '#ajax' => [
        'callback' => [$this, 'rebuildCallback'],
        'event' => 'change',
        'wrapper' => 'flight-entry-form-wrapper',
      ],
    ];

    // Read-only cruise speed field
    $form['outbound']['cruise_speed'] = [
      '#type' => 'item',
      '#title' => $this->t('Cruise Speed'),
      '#markup' => $cruise_speed
        ? $cruise_speed . ' knots (Mach ' . number_format($cruise_speed / 573, 2) . ')'
        : '—',
    ];

    $form['outbound']['dep_time'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Departure Time (HH:MM)'),
      '#default_value' => $dep_time_raw,
      '#description' => $this->t('Enter time in 24-hour format, e.g., 13:45'),
      '#ajax' => [
        'callback' => [$this, 'rebuildCallback'],
        'event' => 'change',
        'wrapper' => 'flight-entry-form-wrapper',
      ],
    ];

    // Breakdown fields
    $form['outbound']['flight_distance'] = [
      '#type' => 'item',
      '#title' => $this->t('Flight Distance (Great Circle)'),
      '#markup' => $distance ? $distance . ' NM' : '—',
    ];

    $form['outbound']['total_distance'] = [
      '#type' => 'item',
      '#title' => $this->t('Total Distance (incl. SID/STARs)'),
      '#markup' => $total_distance ? $total_distance . ' NM' : '—',
    ];

    $form['outbound']['airborne_duration'] = [
      '#type' => 'item',
      '#title' => $this->t('Airborne Duration'),
      '#markup' => $airborne_duration ? $this->formatDuration($airborne_duration) : '—',
    ];

    $form['outbound']['block_time'] = [
      '#type' => 'item',
      '#title' => $this->t('Block Time (incl. taxi)'),
      '#markup' => $block_duration ? $this->formatDuration($block_duration) : '—',
    ];

    $form['outbound']['turnaround_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Turnaround Time (minutes)'),
      '#default_value' => $return['turnaround_minutes'] ?? 30,
      '#ajax' => [
        'callback' => [$this, 'rebuildCallback'],
        'event' => 'change',
        'wrapper' => 'flight-entry-form-wrapper',
      ],
    ];

    // Outbound suggested time or instruction (now based on block time for realism)
    if ($dep_time_raw && preg_match('/^\d{1,2}:\d{2}$/', $dep_time_raw) && $block_duration) {
      [$h, $m] = explode(':', $dep_time_raw);
      $dep_timestamp = mktime((int) $h, (int) $m, 0, date('n'), date('j'), date('Y'));
      $turnaround = (int) ($outbound['turnaround_minutes'] ?? 30);
      $rounded_block = round($block_duration / 10) * 10;
      $arrival_timestamp = $dep_timestamp + ($rounded_block * 60);
      $suggested_return = date('H:i', $arrival_timestamp + ($turnaround * 60));


      $form['outbound']['suggested_time'] = [
        '#type' => 'item',
        '#title' => $this->t('Suggested Return Departure'),
        '#markup' => $suggested_return,
      ];
    }
    else {
      $form['outbound']['suggested_time'] = [
        '#type' => 'item',
        '#markup' => $this->t('Enter a departure time and arrival ICAO so the suggested return departure can be calculated'),
      ];
    }


    return $form;
  }

  public function rebuildCallback(array &$form, FormStateInterface $form_state) {
    $form_state->setRebuild(TRUE);
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $outbound = $form_state->getValue('outbound');
    \Drupal::messenger()->addMessage($this->t('Flight @callsign saved.', ['@callsign' => $outbound['callsign']]));
    \Drupal::logger('flight_scheduler')->notice('Flight entry saved: @callsign', ['@callsign' => $outbound['callsign']]);
  }

  private function calculateDistance($dep_icao, $arr_icao) {
    if (!$dep_icao || !$arr_icao) return null;
    $dep = flight_scheduler_get_airport_coords($dep_icao);
    $arr = flight_scheduler_get_airport_coords($arr_icao);
    if (!$dep || !$arr || !$dep['lat'] || !$arr['lat']) return null;

    $lat1 = deg2rad($dep['lat']);
    $lon1 = deg2rad($dep['lon']);
    $lat2 = deg2rad($arr['lat']);
    $lon2 = deg2rad($arr['lon']);
    $delta_lat = $lat2 - $lat1;
    $delta_lon = $lon2 - $lon1;
    $a = sin($delta_lat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($delta_lon / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return round(3440.065 * $c, 0);
  }

  private function calculateFlightLength($distance, $aircraft_code) {
    $aircrafts = $this->getAircraftOptions();
    $speed = $aircrafts[$aircraft_code]['speed'] ?? 265; // fallback
    return round(($distance / $speed) * 60, 2);
  }

  private function getAircraftOptions() {
    return [
      'default' => ['label' => 'Default', 'speed' => 320],
      'DHC-6'   => ['label' => 'DHC-6', 'speed' => 140],
      'AT76'    => ['label' => 'ATR 72-600', 'speed' => 200],
    ];
  }

  /**
   * Convert minutes to hours and minutes, rounded to nearest 10 minutes.
   */
  private function formatDuration($minutes) {
    if (!$minutes) {
      return '—';
    }

    // Round to nearest 10 minutes
    $rounded = round($minutes / 10) * 10;

    $hours = floor($rounded / 60);
    $mins  = $rounded % 60;

    $result = '';
    if ($hours > 0) {
      $result .= $hours . 'h ';
    }
    $result .= $mins . 'm';

    return $result;
  }

}
