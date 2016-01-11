<?php
namespace Kola;

use DateTime;
use DateTimeZone;
use GuzzleHttp\Client;
use Dotenv\Dotenv;

class AdhanBot
{
  const INIT = "Welcome! AdhanBot is up and running!";
  const IS_RUNNING = "AdhanBot has been up and running";
  const REMINDER_TEXT = "...حي على الصلاة...حي على الفلاح";
  const REMINDER_ATTACHMENT = "The Success you search for calls you FIVE times a day!";
  const API_ALADHAN_BASE_URL = "http://api.aladhan.com/timings";
  const API_SLACK_BASE_URL = "https://slack.com/api/";

  private $status;
  private $method;
  private $latitude;
  private $timezone;
  private $longitude;
  private $httpClient;
  private $webhookUrl;
  private $members = [];
  private $todayFajrTime;
  private $apiEndpointUrl;

  public function __construct()
  {
    $this->loadDotEnv();
    $this->httpClient = new Client;
    $this->method = getenv("METHOD");
    $this->timezone = getenv("TIMEZONE");
    $this->webhookUrl = getenv("SLACK_WEBHOOK_URL");
    $dateTimezone = new DateTimeZone($this->timezone);
    $this->latitude = $dateTimezone->getLocation()["latitude"];
    $this->longitude = $dateTimezone->getLocation()["longitude"];
    $this->apiAlAdhanUrl = $this::API_ALADHAN_BASE_URL . "?latitude=$this->latitude&longitude=$this->longitude&timezonestring=$this->timezone&method=$this->method";
    $this->start();
  }

  private function loadDotEnv() {
    if (getenv("APP_ENV") !== "production") {
      $dotenv = new Dotenv(__DIR__ . "/..");
      $dotenv->load();
    }
  }

  private function loadMembersFromFile() {
    $this->members = [];

    foreach (file("../storage/members.txt") as $line) {
      array_push($this->members, $this->getMemberInfo(trim(substr($line, strpos($line, "=") + 1))));
    }
  }

  private function getMemberInfo($userID) {
    $memberUri = $this::API_SLACK_BASE_URL . "users.info?token=" . getenv("SLACK_AUTH_TOKEN") . "&user=" . $userID;

    return $this->httpClient->request("GET", $memberUri)->getBody();
  }

  private function saveListOfSlackTeamMembersToFile() {
    file_put_contents("../storage/members.json", $this->getListOfSlackTeamMembers());
    // $file = fopen("../storage/members.json", "w");
    // fwrite($file, $this->getListOfSlackTeamMembers());
    // fclose($file);
  }

  private function getListOfSlackTeamMembers() {
    $teamUri = $this::API_SLACK_BASE_URL . "users.list?token=" . getenv("SLACK_AUTH_TOKEN");

    return $this->httpClient->request("GET", $teamUri)->getBody();
  }

  private function getTimeOffset($prayer) {
    $timeOffset = getenv("TIME_OFFSET_IN_MINUTES_" . strtoupper($prayer));

    return $timeOffset ? : 0;
  }

  private function getTimezone($timezone) {
    return new DateTimeZone($timezone);
  }

  private function getPayload($prayer, $member) {
    return json_encode([
      "channel" => json_decode($member)->user->id,
      "text" => $this::REMINDER_TEXT,
      "attachments" => [
         "fields" => ["title" => "$prayer: " . $this::REMINDER_ATTACHMENT . " @" . json_decode($member)->user->name]
      ]
    ]);
  }

  private function sleepTillNextDay(DateTimeZone $dateTimezone) {
    $tmp = DateTime::createFromFormat("H:i", $this->todayFajrTime, $dateTimezone);
    time_sleep_until($tmp->getTimestamp() + 84600);
  }

  private function start() {
    $dateTimezone = $this->getTimezone($this->timezone);
    $this->status = $this::IS_RUNNING;
    $startDate = new DateTime("now", $dateTimezone);
    $file = fopen("../storage/logs", "a");
    echo $this::INIT;

    while (true) {
      $this->loadMembersFromFile();
      $response = $this->httpClient->request('GET', $this->apiAlAdhanUrl);
      $prayerTimes = json_decode($response->getBody())->data->timings;
      $log = "*****************************************************************\n";
      $log .= $this->status . " since " . $startDate->format('D, d M Y H:i:s') . "!";
      fwrite($file, $log);

      foreach ($prayerTimes as $prayer => $time) {
        if ($prayer === "Fajr") {
          $this->todayFajrTime = $time;
        }

        $now = new DateTime("now", $dateTimezone);
        $next = DateTime::createFromFormat("H:i", $time, $dateTimezone);
        $timeDiff = $next->getTimestamp() - $now->getTimestamp();

        if (! ($prayer == "Sunrise" || $prayer == "Sunset" || $timeDiff <= 0)) {
          if (time_sleep_until($next->getTimestamp() + $this->getTimeOffset($prayer))) {
            foreach ($this->members as $member) {
              $this->httpClient->request("POST", $this->webhookUrl, [
                "form_params" => [
                  "payload" => $this->getPayload($prayer, $member)
                ]
              ]);
            }
          }

          fwrite($file, "\nAdhan for $prayer was called on " . $next->format('D, d M Y H:i:s') . ".");
        }
      }

      fwrite($file, "\n\n");
      $this->sleepTillNextDay($dateTimezone);
    }

    fclose($file);
  }
}
?>
