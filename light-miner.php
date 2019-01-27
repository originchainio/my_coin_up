<?php

/*
fork https://github.com/arionum/miner
revise:The algorithmic part makes it applicable to origin
*/

// version: 20190115 test
class Miner{
    public const VERSION = 'v0.1';
    public const MODE_POOL = 'pool';
    public const MODE_SOLO = 'solo';
    public const NODE_STATUS_OK = 'ok';
    private $publicKey;
    private $privateKey;
    private $speed;
    private $avgSpeed;
    private $node;
    private $block;
    private $difficulty;
    private $counter;
    private $allTime;
    private $beginTime;
    private $type;
    private $limit;
    private $worker;
    private $lastUpdate;
    private $submit;
    private $confirm;
    private $found;
    private $height;
    /**
     * Miner constructor.
     *
     * @param $type
     * @param $node
     * @param $public_key
     * @param $private_key
     */
    public function __construct($type, $node, $public_key, $private_key)
    {
        $this->outputHeader();
        $this->checkDependencies();
        if (empty($type) || empty($public_key) || empty($node) || ($type == self::MODE_SOLO && empty($private_key))) {
            echo "Usage:\n\n";
			echo "For Solo mining: ./miner solo <node> <public_key> <private_key>\n";
			echo "For Pool mining: ./miner pool <pool-address> <your-address>\n\n";
            exit;
        }
        if ($type == self::MODE_POOL) {
            $private_key = $public_key;
            // Check address validity
            $dst_b = $this->base58Decode($public_key);
            if (strlen($dst_b) != 64) {
                die("ERROR: Invalid Arionum address!");
            }
        }
        $worker = uniqid();
        $this->prepare($public_key, $private_key, $node, $type, $worker);
        $res = $this->update();
        if (!$res) {
            die("ERROR: Could not get mining info from the node");
        }
        
    }
    /**
     * @param string $publicKey
     * @param string $privateKey
     * @param string $node
     * @param string $type
     * @param string $worker
     */
    public function prepare(string $publicKey, string $privateKey, string $node, string $type, string $worker)
    {
        $this->publicKey = $publicKey;
        $this->privateKey = $privateKey;
        $this->node = $node;
        $this->type = $type;
        $this->worker = $worker;
        $this->counter = 0;
        $this->submit = 0;
        $this->confirm = 0;
        $this->found = 0;
    }
    /**
     * @return bool
     */
    public function update(): bool
    {
        $this->lastUpdate = time();
        echo "--> Updating mining info\n";
        $extra = "";
        if ($this->type == self::MODE_POOL) {
            $extra = "&worker=".$this->worker."&address=".$this->privateKey."&hashrate=".$this->speed;
        }
        $res = file_get_contents($this->node."/mine.php?q=info".$extra);

        $info = json_decode($res, true);

        if ($info['status'] != self::NODE_STATUS_OK) {
            return false;
        }
        $data = $info['data'];
        $this->block = $data['block'];
        $this->difficulty = $data['difficulty'];
        $this->limit = 50;
        if ($this->type === self::MODE_POOL) {
            $this->limit = $data['limit'];
            $this->publicKey = $data['public_key'];
        }
        $this->height = $data['height'];
        echo "Min DL: ".$this->limit."\n";
        return true;
    }
    /**
     * @param string $nonce
     * @param string $argon
     * @return bool
     */
    private function submit(string $nonce, string $argon): bool
    {
        echo "--> Submitting nonce $nonce / $argon\n";
        $postData = http_build_query(
            [
                'argon'       => $argon,
                'nonce'       => $nonce,
                'private_key' => $this->privateKey,
                'public_key'  => $this->publicKey,
                'address'     => $this->privateKey,
            ]
        );
        $opts = [
            'http' =>
                [
                    'method'  => 'POST',
                    'header'  => 'Content-type: application/x-www-form-urlencoded',
                    'content' => $postData,
                ],
        ];
        $context = stream_context_create($opts);
        $res = file_get_contents($this->node."/mine.php?q=submitNonce", false, $context);
        $data = json_decode($res, true);

        //echo_array($res);
        if ($data['status'] == self::NODE_STATUS_OK) {
            echo "\n--> Nonce confirmed.\n";
            return true;
        } else {
            echo "--> The nonce did not confirm.\n\n";
            return false;
        }
    }
    /**
     * Run the miner.
     */
    public function run()
    {
        $this->allTime = microtime(true);
        $this->beginTime = time();
        $it = 0;
        $this->counter = 0;
        $start = microtime(true);
        while (1) {
            $this->counter++;
            if (time() - $this->lastUpdate > 2) {
                echo "--> Hash: ".number_format($this->speed,2)." H/s   ".
                    "Average: ".number_format($this->avgSpeed,2)." H/s  ".
                    "Total hashes: ".$this->counter."  ".
                    "Mining Time: ".(time() - $this->beginTime)."  ".
                    "Shares: ".$this->confirm." ".
                    "Finds: ".$this->found."\n";
                $this->update();
            }
            $nonce = base64_encode(openssl_random_pseudo_bytes(32));
            $nonce = preg_replace("/[^a-zA-Z0-9]/", "", $nonce);
            //echo 'nonce:'.$nonce."\n";
            $base = $this->publicKey."-".$nonce."-".$this->block."-".$this->difficulty;
            $argon = password_hash(
                    $base,
                    PASSWORD_ARGON2I,
                    ['memory_cost' => 16384, "time_cost" => 4, "threads" => 4]
                );
            $hash = $base.$argon;
            $hash = hash("sha512", $hash, true);

            $hash = hash("sha512", $hash);
            $m = str_split($hash, 2);
            $duration = hexdec($m[10]).hexdec($m[15]).hexdec($m[20]).hexdec($m[23]).
                hexdec($m[31]).hexdec($m[40]).hexdec($m[45]).hexdec($m[55]);
            $duration = ltrim($duration, '0');
            $result = gmp_div($duration, $this->difficulty);
            if ($result > 0 && $result <= $this->limit) {

                if (!password_verify($base, $argon)) {
                   echo "verify failed"."\n";
                }else{
                    echo "verify success"."\n";
                }


                $argon=substr($argon, 29);
                $confirmed = $this->submit($nonce, $argon);
                //echo "\nARGON: $argon\n";
                //echo "\nBase: $base\n";
                if ($confirmed && $result <= 50) {
                    $this->found++;
                } elseif ($confirmed) {
                    $this->confirm++;
                }
                $this->submit++;
                //sleep(3);  //test
            }
            $it++;
            if ($it == 10) {
                $it = 0;
                $end = microtime(true);
                $this->speed = 10 / ($end - $start);
                $this->avgSpeed = $this->counter / ($end - $this->allTime);
                $start = $end;
            }
        }
    }
    /**
     * @param array $source
     * @param mixed $source_base
     * @param mixed $target_base
     * @return array
     *
     * @author Mika Tuupola
     * @link   https://github.com/tuupola/base58
     */
    public function baseConvert(array $source, $source_base, $target_base)
    {
        $result = [];
        while ($count = count($source)) {
            $quotient = [];
            $remainder = 0;
            for ($i = 0; $i != $count; $i++) {
                $accumulator = $source[$i] + $remainder * $source_base;
                $digit = (integer)($accumulator / $target_base);
                $remainder = $accumulator % $target_base;
                if (count($quotient) || $digit) {
                    array_push($quotient, $digit);
                };
            }
            array_unshift($result, $remainder);
            $source = $quotient;
        }
        return $result;
    }
    /**
     * @param mixed $data
     * @param bool  $integer
     * @return int|string
     *
     * @author Mika Tuupola
     * @link   https://github.com/tuupola/base58
     */
    public function base58Decode($data, $integer = false)
    {
        $data = str_split($data);
        $data = array_map(function ($character) {
            $chars = "123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz";
            return strpos($chars, $character);
        }, $data);
        /* Return as integer when requested. */
        if ($integer) {
            $converted = $this->baseConvert($data, 58, 10);
            return (integer)implode("", $converted);
        }
        $converted = $this->baseConvert($data, 58, 256);
        return implode("", array_map(function ($ascii) {
            return chr($ascii);
        }, $converted));
    }
    /**
     * Check for the required dependencies.
     */
    public function checkDependencies()
    {
        if (!extension_loaded("gmp")) {
            die("The GMP PHP extension is missing.");
        }
        if (!extension_loaded("openssl")) {
            die("The OpenSSL PHP extension is missing.");
        }
        if (floatval(phpversion()) < 7.2) {
            die("The minimum PHP version required is 7.2.");
        }
        if (!defined("PASSWORD_ARGON2I")) {
            die("The PHP version is not compiled with argon2i support.");
        }
    }
    /**
     * Output the miner header text.
     */
    public function outputHeader()
    {
        echo "============================\n";
        echo "== Origin Miner ".self::VERSION." ==\n";
        echo "== www.originchain.io    ==\n";
        echo "============================\n\n";
    }
}

error_reporting(0);
//ini_set("memory_limit", "5G");
$type = trim($argv[1]);
$node = trim($argv[2]);
$public_key = trim($argv[3]);
$private_key = trim($argv[4]);
$mine=new Miner($type, $node, $public_key, $private_key);
$mine->run();




function echo_array($a) { echo "<pre>"; print_r($a); echo "</pre>"; }










?>