<?php

namespace PrisonCells;

use pocketmine\level\Position;
use pocketmine\utils\TextFormat;
use pocketmine\item\Item;
use pocketmine\Server;
use pocketmine\nbt\tag\StringTag;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\tile\Sign;
use pocketmine\utils\Config;

class Cell {

    protected $prisonCells;

    protected $uID;
    protected $cfg;
    protected $owner = null;
    protected $expireTime = null;
    protected $sign;
    protected $pos1;
    protected $pos2;
    protected $price = 10000;
    protected $flags = [];
    protected $home = null;
    protected $lvl = null;

    public function __construct(PrisonCells $prisonCells, $uID, Sign $sign, Vector3 $pos1, Vector3 $pos2, $flags = ["edit" => true], $price = 1000, Vector3 $home, $owner = "", $expire = null) {
        $this->prisonCells = $prisonCells;
        $this->lvl = $sign->getLevel();

        // e.g. c1    - Config: c1.yml
        $this->uID = $uID;
        $this->cfg = new Config($prisonCells->getDataFolder() . "storing-data/cells/{$uID}.yml", Config::YAML);

        $this->cfg->setNested("sign.x", $sign->x);
        $this->cfg->setNested("sign.y", $sign->y);
        $this->cfg->setNested("sign.z", $sign->z);

        $this->cfg->setNested("pos1.x", $pos1->x);
        $this->cfg->setNested("pos1.y", $pos1->y);
        $this->cfg->setNested("pos1.z", $pos1->z);

        $this->cfg->setNested("pos2.x", $pos2->x);
        $this->cfg->setNested("pos2.y", $pos2->y);
        $this->cfg->setNested("pos2.z", $pos2->z);

        $this->cfg->setNested("home.x", $home->x);
        $this->cfg->setNested("home.y", $home->y);
        $this->cfg->setNested("home.z", $home->z);

        $this->cfg->setNested("flags.edit", $flags["edit"]);
        $this->cfg->setNested("flags.public", $flags["public"]);

        $this->cfg->set("price", $price);
        $this->cfg->set("owner", $owner instanceof Prisoner ? strtolower($owner->getName()) : strtolower($owner));
        $this->cfg->set("expire", $expire);

        $this->cfg->save();

        $this->pos1 = $pos1;
        $this->pos2 = $pos2;

        $this->home = $home;

        $this->flags = $flags;
        $this->price = $price;
        $this->sign = $sign;
        $this->owner = $this->cfg->get("owner");
        $this->expireTime = $expire;

        $this->updateSign();
    }

    public function updateConfig($async = true) {
        $this->cfg->setNested("flags.edit", $this->flags["edit"]);
        $this->cfg->setNested("flags.public", $this->flags["public"]);

        $this->cfg->set("expire", $this->expireTime);
        $this->cfg->set("owner", $this->owner === null ? "" : $this->owner);

        $this->cfg->save($async);
    }

    public function getID() {
        return $this->uID;
    }

    public function getHome() {
        return $this->home;
    }

    public function setFlag($flag, $value) {
        $this->flags[$flag] = $value;
        $this->updateConfig();
    }

    public function getFlag($flag) {
        return $this->flags[$flag];
    }

    public function reset() {
        $this->flags = [
            "edit" => $this->flags["edit"],
            "public" => $this->prisonCells->getConfig()->getNested("cells.default.flags.public"),
        ];

        if ($p = $this->prisonCells->getServer()->getPlayerExact(strtolower($this->owner))) {
            if ($p instanceof Prisoner) {
                $p->removeCell($this);
                $text = $this->prisonCells->getConfig()->getNested("cells.messages.cell_expired");
                $text = str_replace("{CELL_ID}", $this->uID, $text);
                $p->sendMessage($text);
            }
        }

        $this->owner = "";
        $this->expireTime = PHP_INT_MAX;

        $this->updateConfig();
        $this->updateSign();
    }

    public function updateSign() {
        if ($this->owner != null && $this->owner !== "") {
            // RENTED
            $ownerName = strtolower($this->owner);
            if ($p = $this->prisonCells->getServer()->getPlayerExact($ownerName)) {
                $ownerName = $p->getName();
            }

            $cfg = $this->prisonCells->getConfig()->getNested("cells.sign.rented");
            $texts = [
                $cfg[0],
                $cfg[1],
                $cfg[2],
                $cfg[3],
            ];

            foreach ($texts as $key => $text) {
                $texts[$key] = str_replace(["{CELL_ID}", "{OWNER}"], [$this->uID, $ownerName], $text);
            }

            $namedTag = $this->sign->getNamedTag();
            if ($namedTag instanceof CompoundTag) {
                $namedTag->setTag(new StringTag("Text1", $texts[0]));
                $namedTag->setTag(new StringTag("Text2", $texts[1]));
                $namedTag->setTag(new StringTag("Text3", $texts[2]));
                $namedTag->setTag(new StringTag("Text4", $texts[3]));
                $namedTag->setTag(new ByteTag("RentedCell", 0));
            }

            $this->sign->spawnToAll();
            $this->lvl->clearChunkCache($this->sign->getX() >> 4, $this->sign->getZ() >> 4);
        } else {
            // OPEN
            $cfg = $this->prisonCells->getConfig()->getNested("cells.sign.not_rented");
            $texts = [
                $cfg[0],
                $cfg[1],
                $cfg[2],
                $cfg[3],
            ];

            foreach ($texts as $key => $text) {
                $texts[$key] = str_replace(["{CELL_ID}", "{PRICE}"], [$this->uID, $this->price], $text);
            }

            $namedTag = $this->sign->getNamedTag();
            if ($namedTag instanceof CompoundTag) {
                $namedTag->setTag(new StringTag("Text1", $texts[0]));
                $namedTag->setTag(new StringTag("Text2", $texts[1]));
                $namedTag->setTag(new StringTag("Text3", $texts[2]));
                $namedTag->setTag(new StringTag("Text4", $texts[3]));
                $namedTag->setTag(new ByteTag("RentedCell", 0));
            }

            $this->sign->spawnToAll();
            $this->lvl->clearChunkCache($this->sign->getX() >> 4, $this->sign->getZ() >> 4);
        }
    }

    public function getPrice() {
        return $this->price;
    }

    public function isInside(Position $pos) {
        if ($pos->getLevel()->getFolderName() !== $this->lvl->getFolderName()) {
            return false;
        }
        $xMin = min($this->pos1->x, $this->pos2->x);
        $xMax = max($this->pos1->x, $this->pos2->x);
        $yMin = min($this->pos1->y, $this->pos2->y);
        $yMax = max($this->pos1->y, $this->pos2->y);
        $zMin = min($this->pos1->z, $this->pos2->z);
        $zMax = max($this->pos1->z, $this->pos2->z);

        /*
         * If x is not inside the area or
         * If y is not inside the area or
         * If z is not inside the area then
         * return false
         */
        if ($pos->x > $xMax || $pos->x < $xMin) {
            return false;
        }
        if ($pos->y > $yMax || $pos->y < $yMin) {
            return false;
        }
        if ($pos->z > $zMax || $pos->z < $zMin) {
            return false;
        }

        return true;
    }

    public function isOwner(Prisoner $player) {
        return strtolower($player->getName()) === strtolower($this->owner);
    }

    public function getOwner() {
        return $this->owner;
    }

    /**
     * @return \pocketmine\level\Level
     */
    public function getLevel() {
        return $this->lvl;
    }

    public function expired() {
        return time() >= $this->expireTime;
    }

    public function addExpireTime() {
        if ($this->expireTime - time() < 345600) { // 96h = 345600
            $this->expireTime = $this->expireTime + 86400; // 24h = 86400sec
            $this->updateConfig();
            return true;
        }
        return false;
    }

    public function rent(Prisoner $player) {
        $this->owner = strtolower($player->getName());
        $this->expireTime = time() + 86400; // 24h = 86400sec

        $this->owner = strtolower($player->getName());
        $this->cfg->set("owner", $this->owner);
        $this->cfg->set("expire", $this->expireTime);
        $this->cfg->save();

        $this->updateSign();

        $text = $this->prisonCells->getConfig()->getNested("cells.messages.cell_rented");
        $text = str_replace(["{CELL_ID}", "{PLAYER}"], [$this->uID, $player->getName()], $text);
        $player->sendMessage($text);
    }

    public function clear() {
        $this->lvl = null;
        $this->prisonCells = null;
    }

}
