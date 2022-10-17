#!/usr/bin/php
<?php
error_reporting(-1);

interface Controller {
  public function getAvailable();
  public function getOSDrives();
  public function getDriveWearLevels($drive);
}

final class StatusCode {
  const RET_OK = 0;
  const RET_WARNING = 1;
  const RET_CRITICAL = 2;
  const RET_UNKNOWN = 3;

  private function __construct(){
    throw new Exception();
  }
};

class Disks implements Controller {
  private $lsi;
  private $nvme;
  private $controllers;
  private $host;
  private $available = false;

  public function __construct() {
    $lsi = new LSI();
    $nvme = new NVME();
    $host = new Host();

    if ( $lsi->getAvailable()) { $this->controllers[] = &$lsi;  $this->available = true; };
    if ($nvme->getAvailable()) { $this->controllers[] = &$nvme; $this->available = true; };
    if ($host->getAvailable()) { $this->controllers[] = &$host; $this->available = true; };
  }

  public function getAvailable() {
    return $this->available;
  }

  public function getOSDrives() {
    $drives = array();
    foreach($this->controllers as $controller) {
      $drives = array_merge($drives, $controller->getOSDrives());
    }

    return $drives;
  }

  public function getDriveWearLevels($drive) {
    foreach($this->controllers as $controller) {
      if (in_array($drive, $controller->getOSDrives())) {
        return $controller->getDriveWearLevels($drive);
      }
    }

    return array("drive" => $drive, "wearLevels" => array(-1, -1));
  }
}

class NVME implements Controller {
  // PHP 7 does not allow defining variable types.
  private $available = false;
  private $devices = array();
  //private bool $available = false;
  //private array $devices = array();

  public function __construct() {
    $this->getAvailable();
  }

  public function getAvailable() {
    if (!file_exists("/usr/sbin/nvme")) { $this->available = false; return $this->available; };
    if (!file_exists("/bin/lsblk"))     { $this->available = false; return $this->available; };
    if ($this->available == true)       { return true; };

    $this->available = false;

    exec("lsblk -d -o name", $output, $resultCode); // Original command was name,rota. However, NVME is usually solid-state (there are spinning nvme disks...)
    for ($i = 0; $i < count($output); $i++) {
      if (substr($output[$i], 0, 4) == "nvme") {
        $this->available = true;
        $this->devices = array("/dev/" . $output[$i]);
      };
    }

    return $this->available;
  }

  public function getOSDrives() {
    return $this->devices;
  }

  public function getDriveWearLevels($drive) {
    exec("/usr/sbin/nvme intel smart-log-add " . $drive, $output, $resultCode);
    for($i = 0; $i < count($output); $i++) {
      if (substr($output[$i], 0, 13) == "wear_leveling") {
        $wearLevel = trim(substr(explode(":", $output[$i])[1], 0, 5));
        $wearLevel = trim($wearLevel, "%");
        return array("drive" => $drive, "wearLevels" => array(0 => $wearLevel));
      }
    }
  }
}

class Host implements Controller {
  // PHP 7 does not allow defining variable types.
  private $available = false;
  private $devices = array();
  //private bool $available = false;
  //private array $devices = array();

  public function __construct() {
    $this->getAvailable();
  }

  public function getAvailable() {
    if (!file_exists("/bin/lsblk"))     { $this->available = false; return $this->available; };
    if ($this->available == true)       { return true; };

    $this->available = false;

    exec("lsblk -d -o rota,name", $output, $resultCode); // Original command was name,rota. However, NVME is usually solid-state (there are spinning nvme disks...)
    for ($i = 0; $i < count($output); $i++) {
      if (substr($output[$i], 0, 4) == "  0") {
        $device = trim(substr($output[$i], 5, 10));
        if (strpos($device, "nvme") == false) {
          $this->available = true;
          $this->devices = array_merge($this->devices, array("/dev/" . $device));
        };
      };
    }
    return $this->available;
  }

  public function getOSDrives() {
    return $this->devices;
  }

  public function getDriveWearLevels($drive) {
    exec("/usr/sbin/smartctl -A -d auto " . $drive, $output, $resultCode);
    for ($i = 0; $i < count($output); $i++) {
      if (substr($output[$i], 0, 3) == "177" || substr($output[$i], 0, 3) == "233") {
        return array("drive" => $drive, "wearLevels" => array(0 => intval(trim(explode(" ", $output[$i])[9]))));
      }
    }

  }
}

class LSI implements Controller {
  // PHP 7 does not allow defining variable types.
  private $controllerCount = 0;
  private $vd = [];
  private $available = false;
  //private int $controllerCount = 0;
  //private array $vd = [];
  //private bool $available = false;

  public function __construct() {
    if ($this->getAvailable() === false) {
      $this->controllerCount = 0;
      return;
    };

    $this->getControllerCount();

    for ($i = 0; $i < $this->controllerCount; $i++) {
      $vdCount = $this->getControllerVDCount($i);
      for ($vdIterate = 0; $vdIterate < $this->controllerCount; $vdIterate++) {
        $this->getVDDriveName($i, $vdIterate);
        $this->getDeviceIDs($i, $vdIterate);
      }
    }
  }

  public function getAvailable() {
    if ($this->available == true) { return true; };

    if (!file_exists("/usr/bin/storcli")) {
      $this->available = false;
    } else {
      $this->available = true;
    };

    return $this->available;
  }

  private function getControllerCount() {
    exec("/usr/bin/storcli show ctrlcount", $output, $resultCode);
    $this->controllerCount = trim(explode("=", $output[6])[1]);

    return $resultCode;
  }

  private function getControllerVDCount(int $controller) {
    exec("storcli /c" . $controller . "/vall show", $output, $resultCode);

    for ($i = 0; $i < 13; $i++) { unset($output[$i]); };        // Pop de 1e 14 regels de prullenbak in
    $output = array_values($output);                      // Reindex keys (begin weer bij 0)

    for ($i = 0; $i < count($output); $i++) {
      if ($output[$i] == "---------------------------------------------------------------") { $vdCount = $i; };
    }

    $this->vd[$controller]["count"] = $vdCount;
    return $vdCount;
  }

  private function getVDDriveName(int $controller, int $vd) {
    exec("storcli /c" . $controller . "/v" . $vd . " show all", $output, $resultCode);

    for($i = 0; $i < count($output); $i++) {
      if (substr($output[$i], 0, 16) == "OS Drive Name = ") {
        $drive = trim(explode(" = ", $output[$i])[1]);

        $this->vd[$controller][$vd]["os"] = $drive;
        return;
      }
    }
  }

  private function getDeviceIDs(int $controller, int $vd) {
    exec("storcli /c" . $controller . "/v" . $vd . " show all", $output, $resultCode);

    for($i = 0; $i < count($output); $i++) {
      if (substr($output[$i], 0, 11) == "EID:Slt DID") {
        $start = $i + 2;
      }
      if ($output[$i] == "----------------------------------------------------------------------------------------") {
        $stop = $i;
      }
    }

    for($i = $start; $i < $start + ($stop - $start); $i++) {
      if (substr($output[$i],37,3) == "SSD") { # Filter devices with medium SSD
        $did = trim(explode(" ", $output[$i])[5]);
        $this->vd[$controller][$vd]["did"][$did] = $did;
      }
    }
  }

  public function getOSDrives() {
    foreach($this->vd as $vd) {
      for($i = 0; $i < $vd["count"]; $i++) {
       $drives[] = $vd[$i]["os"];
      }
    }

    if (count($this->vd) > 0) {
      return $drives;
    } else {
      return array();
    };
  }

  public function getDeviceIDsFromOSDrive(string $osDrive) {
    foreach($this->vd as $vd) {
      for($i = 0; $i < $vd["count"]; $i++) {
       if ($vd[$i]["os"] == $osDrive) {
         $did = $vd[$i]["did"];
       }
      }
    }
    return array_values($did);
  }

  public function getDriveWearLevels($drive) {
    $didList = $this->getDeviceIDsFromOSDrive($drive);
    $wearLevelArray = array();
    foreach($didList as $did) {
      $wearLevelArray[$did] = $this->getLSIDeviceWearLevel($drive, $did);
    }

    return array("drive" => $drive, "wearLevels" => $wearLevelArray);
  }

  public function getLSIDeviceWearLevel(string $osName, int $did) {
    exec("/usr/sbin/smartctl -A -d megaraid," . $did . " " . $osName, $output, $resultCode);
    for ($i = 0; $i < count($output); $i++) {
      if (substr($output[$i], 0, 3) == "177" || substr($output[$i], 0, 3) == "233") {
        return intval(trim(explode(" ", $output[$i])[9]));
      }
    }
  }
}

# SSD OK: Drive 9 on 0 WLC/MWI 99

$disks = new Disks();
$perfLine = "";
$retValue = StatusCode::RET_OK;
foreach($disks->getOSDrives() as $disk) {
  $data = $disks->getDriveWearLevels($disk);
  $wearLevels = $data["wearLevels"];
  $perfDiskLine = "";
  foreach($wearLevels as $did => $wearLevel) {
    $perfDiskLine .= "Disk " . $did . ":" . $wearLevel . "%,";

    if ($wearLevel  < 10)                                           { $retValue = StatusCode::RET_CRITICAL; };
    if ($wearLevel  < 30  && $retValue != StatusCode::RET_CRITICAL) { $retValue = StatusCode::RET_WARNING;  };
    if ($wearLevel <= 100 && $retValue < StatusCode::RET_WARNING)   { $retValue = StatusCode::RET_OK;       };
  }

  $perfLine .= $disk . "=" . $perfDiskLine . ";";
}

// 0 = ok, 1 = warning, 2 = critical, 3 = unknown



switch ($retValue) {
  case StatusCode::RET_CRITICAL:
    echo "SSD CRITICAL: One or more below 10% lifetime|" . $perfLine;
    break;
  case StatusCode::RET_WARNING:
    echo "SSD WARNING: One or more below 30% lifetime|" . $perfLine;
    break;
  case StatusCode::RET_OK:
    echo "SSD OK: All SSDs are healthy|" . $perfLine;
    break;
}

return $retValue; 
