<?php

class Database
{
    private $db = [];
    protected static $instance;
 
    function __construct() {
        if (file_exists(__DIR__.'/localdb.json')) {
            $this->db = json_decode(file_get_contents(__DIR__.'/localdb.json'), true);
        }
    }

    static function get() : Database {
        if (self::$instance == null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    function save() {
        file_put_contents(__DIR__.'/localdb.json', json_encode($this->db));
    }

    function createUser(int $userId) {
        $this->db[$userId] = [
            "userId" => $userId,
            "currency" => 0,
            "purchases" => [],
            "debt" => 0,
            "multiplier" => 2
        ];
        $this->save();
    }

    function getUserData(int $userId) : array {
        if (!isset($this->db[$userId])) {
            $this->createUser($userId);
        }

        return $this->db[$userId];
    }

    function updateUserBalance(int $userId, int $newBalance) {
        if (!isset($this->db[$userId])) {
            $this->createUser($userId);
        }

        $this->db[$userId]['currency'] = $newBalance;
        $this->save();
    }

    function addMoney(int $userId, int $add) {
        if (!isset($this->db[$userId])) {
            $this->createUser($userId);
        }

        $this->db[$userId]['currency'] = $this->getUserBalance($userId) + $add;
        $this->save();
    }

    function removeMoney(int $userId, int $add) {
        if (!isset($this->db[$userId])) {
            $this->createUser($userId);
        }

        $this->db[$userId]['currency'] = $this->getUserBalance($userId) - $add;
        $this->save();
    }

    function getUserBalance(int $userId) {
        if (!isset($this->db[$userId])) {
            $this->createUser($userId);
        }

        return $this->db[$userId]['currency'];
    }

    function getUserDebt(int $userId) {
        if (!isset($this->db[$userId])) {
            $this->createUser($userId);
        }

        if (!isset($this->db[$userId]['debt'])) {
            $this->db[$userId]['debt'] = 0;
        }

        return $this->db[$userId]['debt'];
    }
    
    function setUserDebt(int $userId, int $debt) {
        if (!isset($this->db[$userId])) {
            $this->createUser($userId);
        }

        $this->db[$userId]['debt'] = $debt;
    }

    function decreaseUserDebt(int $userId, int $howmuch) {
        if (!isset($this->db[$userId])) {
            $this->createUser($userId);
        }

        $this->db[$userId]['debt'] = $this->db[$userId]['debt'] - $howmuch;
    }

    function takeLoan(int $userId, int $amount) {
        if (!isset($this->db[$userId])) {
            $this->createUser($userId);
        }

        if (!isset($this->db[$userId]['debt'])) {
            $this->db[$userId]['debt'] = 0;
        }

        if ($amount < 0) {
            return [
                "success" => false,
                "message" => sprintf("should be more than 0", $amount)
            ];
        }

        if ($this->getUserDebt($userId) + $amount <= BANK_LOAN_LIMIT) {
            $this->setUserDebt($userId, $this->getUserDebt($userId) + $amount);
            $this->addMoney($userId, $amount);

            $this->save();

            return [
                "success" => true,
                "message" => sprintf("successfully took a loan (%d)", $amount)
            ];
        } else {
            return [
                "success" => false,
                "message" => sprintf("its too much! your debt: %d", $this->getUserDebt($userId))
            ];
        }
    }

    function payDebt(int $userId) : bool {
        if ($this->getUserDebt($userId) > 0) {
            $this->updateUserBalance($userId, $this->getUserBalance($userId) - $this->getUserDebt($userId));
            $this->setUserDebt($userId, 0);
            
            $this->save();
            return true;
        }

        $this->save();
        return false;
    }

    function give(int $user1, int $user2, int $amt) : int {
        if ($amt <= 0) {
            return 2;
        }

        if ($this->getUserBalance($user1) >= $amt) {
            $this->removeMoney($user1, $amt);
            $this->addMoney($user2, $amt);

            return 0;
        }

        return 1;
    }

    function getLeaders() {
        $data = $this->db;
        $finaldata = [];

        $currencies = array_column($this->db, 'currency', 'userId');

        array_multisort($currencies, SORT_DESC, $data);

        for ($i = 0; $i < 10; $i++) { 
            if (isset($data[$i])) {
                $finaldata[] = $data[$i];
            }
        }

        return $finaldata;
    }

    function getUserMultiplier(int $userId) : int {
        if (!isset($this->db[$userId])) {
            $this->createUser($userId);
        }

        if (!isset($this->db[$userId]['multiplier'])) {
            $this->db[$userId]['multiplier'] = 2;
        }

        return $this->db[$userId]['multiplier'];
    }

    function setUserMultiplier(int $userId, int $multiplier) {
        if (!isset($this->db[$userId])) {
            $this->createUser($userId);
        }

        $this->db[$userId]['multiplier'] = $multiplier;
    }

    function upgradeUserMultiplier(int $userId) {
        if (!isset($this->db[$userId])) {
            $this->createUser($userId);
        }

        $price = ($this->getUserMultiplier($userId) + 1) * 5000;

        if ($this->getUserBalance($userId) < $price) {
            return 1;
        }

        $this->removeMoney($userId, $price);
        $this->setUserMultiplier($userId, $this->getUserMultiplier($userId) + 1);

        return 0;
    }
}
