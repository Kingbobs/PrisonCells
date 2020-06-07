<?php

namespace PrisonCells;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerCreationEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\item\Item;
use pocketmine\level\format\Chunk;
use pocketmine\level\Level;
use pocketmine\level\Location;
use pocketmine\level\Position;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\plugin\PluginBase;
use pocketmine\tile\Sign;
use pocketmine\tile\Tile;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;

class PrisonCells extends PluginBase implements Listener{

	public static $cells = [];
	protected static $instance;
	protected $setUp = [];

	private $cellWorld;

	/** @var string[] */
	protected $movementCache = [];

	/**
	 * @return PrisonCells
	 */
	public static function getInstance(){
		return self::$instance;
	}

	public function onEnable(){
		$this->getLogger()->info("PrisonCells starting...");

		self::$instance = $this;

		$this->getServer()->getPluginManager()->registerEvents($this, $this);

		$this->saveDefaultConfig();

		if(!is_dir($this->getDataFolder() . "storing-data/cells/")){
			mkdir($this->getDataFolder() . "storing-data/");
			mkdir($this->getDataFolder() . "storing-data/cells/");
		}

		// Tasks
		//$this->getServer()->getScheduler()->scheduleRepeatingTask(new checkPositionTask($this), 20);
		$this->getServer()->getScheduler()->scheduleRepeatingTask(new updateCellsTask($this), 20);

		$this->reloadConfig();

		$this->loadCells();

		$this->cellWorld = $lvlString = $this->getConfig()->getNested("cells.default.world");
		$this->getServer()->loadLevel($this->cellWorld);

		$this->getLogger()->info("PrisonCells started!");
	}

	public function getCellByID($id){
		if(isset(self::$cells[$id])) return self::$cells[$id];
		return null;
	}

	public function loadCells(){
		$path = $this->getDataFolder() . "storing-data/cells/";

		foreach(scandir($path) as $file){
			$parts = explode(".", $file);
			if($parts[1] === "yml"){
				$cellName = $uID = $parts[0];
				$cfg = new Config($path . $file, Config::YAML);
				$uID = str_replace($path, "", $cellName);

				$lvlString = $this->getConfig()->getNested("cells.default.world");
				$this->getServer()->loadLevel($lvlString);
				$lvl = $this->getServer()->getLevelByName($lvlString);
				$signPos = new Vector3($cfg->getNested("sign.x"), $cfg->getNested("sign.y"), $cfg->getNested("sign.z"));
				$sign = $lvl->getTile($signPos);
				$owner = $cfg->get("owner");
				if(!$sign instanceof Sign) {
					$this->getLogger()->notice("No sign found for cell '{$uID}', creating new sign.");
					if(!$lvl->isChunkLoaded($signPos->x >> 4, $signPos->z >> 4) instanceof Chunk) $lvl->loadChunk($signPos->x >> 4, $signPos->z >> 4, true);
					$sign = Tile::createTile("Sign", $lvl, new CompoundTag("", [
						new StringTag("id", Tile::SIGN),
						new StringTag("Text1", ""),
						new StringTag("Text2", ""),
						new StringTag("Text3", ""),
						new StringTag("Text4", ""),
						new IntTag("x", $signPos->x),
						new IntTag("y", $signPos->y),
						new IntTag("z", $signPos->z),
						new StringTag("CellID", $uID),
						new ByteTag("RentedCell", ($owner === null or $owner === "" ? 0 : 1))
					]));
				}
				$pos1 = new Vector3($cfg->getNested("pos1.x"), $cfg->getNested("pos1.y"), $cfg->getNested("pos1.z"));
				$pos2 = new Vector3($cfg->getNested("pos2.x"), $cfg->getNested("pos2.y"), $cfg->getNested("pos2.z"));
				$flags = [
					"edit" => (bool) $cfg->getNested("flags.edit"),
					"public" => (bool) $cfg->getNested("flags.public"),
				];
				$price = $cfg->get("price");
				$home = $cfg->getNested("home");
				$home = new Vector3($home["x"], $home["y"], $home["z"]);
				$expire = $cfg->get("expire");

				$cell = new Cell($this, $uID, $sign, $pos1, $pos2, $flags, $price, $home, $owner, $expire);
				self::$cells[$uID] = $cell;
			}
		}
	}

	public function onCommand(CommandSender $sender, Command $cmd, $label, array $args){
		switch($cmd->getName())
			return true;
			case "cell":
				if($sender instanceof Prisoner){
					if(!empty($args[0])){
						switch(strtolower($args[0])){
							default:
								$pp = ["public", "private"];
								if(!empty($args[1]) && in_array(strtolower($args[1]), $pp)){
									$cell = $sender->getCellByID($args[0]);
									if($cell !== null && $cell instanceof Cell){
										$currentStatus = $cell->getFlag("public");
										$newStatus = strtolower($args[1]) === "public" ? true : false;
										if($currentStatus != $newStatus){
											$cell->setFlag("public", $newStatus);
											$text = $this->getConfig()->getNested("cells.messages.cell_private_public");
											$text = str_replace(["{CELL_ID}", "{STATUS}"], [$args[0], $args[1]], $text);
											$sender->sendMessage($text);
											return true;
										}else{
											$text = $this->getConfig()->getNested("cells.messages.cell_already_private_public");
											$text = str_replace(["{CELL_ID}", "{STATUS}"], [$args[0], $args[1]], $text);
											$sender->sendMessage($text);
											return true;
										}
									}else{
										$sender->sendMessage($this->getConfig()->getNested("cells.messages.cell_not_yours"));
										return true;
									}
								}else{
									if($sender->hasPermission("Cell.home")){
										$cell = $this->getCellByID($args[0]);
										$msg = $this->getConfig()->getNested("cells.messages.cell_not_existing");
									}else{
										$cell = $sender->getCellByID($args[0]);
										$msg = $this->getConfig()->getNested("cells.messages.cell_not_yours");
									}
									if($cell !== null && $cell instanceof Cell){
										$pos = $cell->getHome();
										$lvl = $cell->getLevel();
										$lvl->loadChunk($pos->x >> 4, $pos->z >> 4, true);
										$lvl->loadChunk($pos->x >> 4, $pos->z >> 4);
										$sender->teleport(new Position($pos->x, $pos->y, $pos->z, $lvl));
										$text = $this->getConfig()->getNested("cells.messages.cell_teleported");
										$text = str_replace("{CELL_ID}", $args[0], $text);
										$sender->sendMessage($text);
										return true;
									}else{
										$sender->sendMessage(TextFormat::RED . $msg);
										return true;
									}
								}
							case "sethome":
								if($sender->hasPermission("Cell.home")){
									if(!isset($this->setUp[$sender->getName()])) $this->setUp[$sender->getName()] = null; // Avoid warnings
									$this->setUp[$sender->getName()]["home"] = $sender->getPosition()->add(0, 0.5, 0);
									$sender->sendMessage($this->getConfig()->getNested("cells.messages.cell_home_set"));
									return true;
								}else{
									$sender->sendMessage($this->getConfig()->getNested("cells.messages.cell_no_permission"));
									return true;
								}
							case "list":
								$cells = $sender->getCells();
								if(count($cells) > 0){
									$uIDs = [];
									foreach($cells as $uID => $cell){
										array_push($uIDs, $uID);
									}
									$cellIDs = implode(TextFormat::GRAY . ", " . TextFormat::YELLOW, $uIDs);
									$text = $this->getConfig()->getNested("cells.messages.cell_your_cells");
									$text = str_replace("{CELL_LIST}", $cellIDs, $text);
									$sender->sendMessage($text);
									return true;
								}else{
									$sender->sendMessage($this->getConfig()->getNested("cells.messages.cell_none"));
									return true;
								}
							case "p1":
							case "p2":
								if($sender->hasPermission("Cell.create")){
									if(!isset($this->setUp[$sender->getName()])) $this->setUp[$sender->getName()] = null; // Avoid warnings
									$pos = new Vector3($sender->getFloorX(), $sender->getFloorY(), $sender->getFloorZ());
									$this->setUp[$sender->getName()][strtolower($args[0])] = $pos;
									$text = $this->getConfig()->getNested("cells.messages.cell_pos_set");
									$text = str_replace("{POS}", $args[0], $text);
									$sender->sendMessage($text);
									return true;
								}else{
									$sender->sendMessage($this->getConfig()->getNested("cells.messages.cell_no_permission"));
									return true;
								}
							case "flag":
								if($sender->hasPermission("Cell.create")){
									if(!empty($args[2]) && strtolower($args[1] === "edit")){
										if(!isset($this->setUp[$sender->getName()])) $this->setUp[$sender->getName()] = null; // Avoid warnings
										$this->setUp[$sender->getName()]["flags"][strtolower($args[1])] = (strtolower($args[2]) === "false" ? false : true);
										$text = $this->getConfig()->getNested("cells.messages.cell_flag_set");
										$text = str_replace(["{FLAG}", "{STATUS}"], [
											$args[1],
											(strtolower($args[2]) === "false" ? "false" : "true"),
										], $text);
										$sender->sendMessage($text);
										return true;
									}else{
										$text = $this->getConfig()->getNested("cells.messages.cell_usage");
										$text = str_replace("{USAGE}", "/cell flag edit <true|false>", $text);
										$sender->sendMessage($text);
										return true;
									}
								}else{
									$sender->sendMessage($this->getConfig()->getNested("cells.messages.cell_no_permission"));
									return true;
								}
							case "sign":
								if($sender->hasPermission("Cell.create")){
									if(!isset($this->setUp[$sender->getName()])) $this->setUp[$sender->getName()] = null; // Avoid warnings

									$lvlString = $this->getConfig()->getNested("cells.default.world");
									if(($level = $this->getServer()->getLevelByName($lvlString)) instanceof Level) {
										$sign = $level->getTile($sender->getTargetBlock(3));
										if(!$sign instanceof Sign) {
											$text = $this->getConfig()->getNested("cells.messages.cell_not_facing_sign");
											$sender->sendMessage($text);
											return true;
										}
										if($sign->namedtag instanceof CompoundTag) {
											$sign->namedtag->RentedCell = new ByteTag("RentedCell", 0);
										}
										$this->setUp[$sender->getName()]["sign"] = $sign;
										$sender->sendMessage($this->getConfig()->getNested("cells.messages.cell_sign_set"));
										return true;
									}else{
										$sender->sendMessage(TextFormat::YELLOW . "Please set the default world in the config file before creating cells!");
										return true;
									}
								}else{
									$sender->sendMessage($this->getConfig()->getNested("cells.messages.cell_no_permission"));
									return true;
								}
							case "create":
								if($sender->hasPermission("Cell.create")){
									if(!isset($this->setUp[$sender->getName()]) || !isset($this->setUp[$sender->getName()]["home"]) || !isset($this->setUp[$sender->getName()]["p1"]) || !isset($this->setUp[$sender->getName()]["p2"]) || !isset($this->setUp[$sender->getName()]["sign"])){
										$sender->sendMessage($this->getConfig()->getNested("cells.messages.cell_setup_before_creating"));
										return true;
									}
									if(!empty($args[1])){
										$uID = $args[1];

										$setup = $this->setUp[$sender->getName()];
										$sign = $setup["sign"];
										$pos1 = $setup["p1"];
										$pos2 = $setup["p2"];
										$home = $setup["home"];
										$flags = [
											"edit" => isset($setup["flags"]["edit"]) ? $setup["flags"]["edit"] : $this->getConfig()->getNested("cells.default.flags.edit"),
											"public" => isset($setup["flags"]["public"]) ? $setup["flags"]["public"] : $this->getConfig()->getNested("cells.default.flags.public"),
										];

										if($sign->namedtag instanceof CompoundTag){
											$sign->namedtag->CellID = new StringTag("CellID", $uID);
										}

										if(empty($args[2])){
											$price = (int) $this->getConfig()->getNested("cells.default.price");
										}else{
											$price = (int) $args[2];
										}

										$cell = new Cell($this, $uID, $sign, $pos1, $pos2, $flags, $price, $home, null, PHP_INT_MAX);

										$text = $this->getConfig()->getNested("cells.messages.cell_created");
										$text = str_replace("{CELL_ID}", $args[1], $text);
										$sender->sendMessage($text);
										self::$cells[$uID] = $cell;

										unset($this->setUp[array_search($sender->getName(), $this->setUp)]);
										return true;
									}else{
										$text = $this->getConfig()->getNested("cells.messages.cell_usage");
										$text = str_replace("{USAGE}", "/cell create <uID> [price]", $text);
										$sender->sendMessage($text);
										return true;
									}
								}else{
									$sender->sendMessage($this->getConfig()->getNested("cells.messages.cell_no_permission"));
									return true;
								}
							case "unclaim":
								if($sender->hasPermission("Cell.unclaim")){
									if(!empty($args[1])){
										$uID = $args[1];
										$cell = $this->getCellByID($uID);
										if($cell !== null && $cell instanceof Cell){
											$cell->reset();
											$text = $this->getConfig()->getNested("cells.messages.cell_reset");
											$text = str_replace("{CELL_ID}", $uID, $text);
											$sender->sendMessage($text);
											return true;
										}else{
											$sender->sendMessage($this->getConfig()->getNested("cells.messages.cell_not_existing"));
											return true;
										}
									}else{
										$text = $this->getConfig()->getNested("cells.messages.cell_usage");
										$text = str_replace("{USAGE}", "/cell unclaim <uID>", $text);
										$sender->sendMessage($text);
										return true;
									}
								}else{
									$sender->sendMessage($this->getConfig()->getNested("cells.messages.cell_no_permission"));
									return true;
								}
							case "extend":
								if(!empty($args[1])){
									$cell = $sender->getCellByID($args[1]);
									if($cell !== null && $cell instanceof Cell){
										if($sender->getMoney() >= $this->getConfig()->getNested("cells.extendPrice")){
											if($cell->addExpireTime()){
												$sender->reduceMoney($this->getConfig()->getNested("cells.extendPrice"));
												$text = $this->getConfig()->getNested("cells.messages.cell_extended");
												$text = str_replace("{CELL_ID}", $args[1], $text);
												$sender->sendMessage($text);
												return true;
											}else{
												$sender->sendMessage($this->getConfig()->getNested("cells.messages.cell_extend_wait"));
												return true;
											}
										}else{
											$sender->sendMessage($this->getConfig()->getNested("cells.messages.cell_not_enough_money"));
											return true;
										}
									}else{
										$sender->sendMessage($this->getConfig()->getNested("cells.messages.cell_not_yours"));
										return true;
									}
								}else{
									$text = $this->getConfig()->getNested("cells.messages.cell_usage");
									$text = str_replace("{USAGE}", "/cell extend <cell_uID>", $text);
									$sender->sendMessage($text);
									return true;
								}
						}
					}else{
						$text = $this->getConfig()->getNested("cells.messages.cell_usage");
						$text = str_replace("{USAGE}", $cmd->getUsage(), $text);
						$sender->sendMessage($text);
						return true;
					}
				}
		}
		return true;
	}

	public function onInteract(PlayerInteractEvent $event){
		$player = $event->getPlayer();
		if($player->getLevel()->getName() === $this->cellWorld) {
			if($player instanceof Prisoner) {
				$sign = $player->getLevel()->getTile($event->getBlock());
				if($sign instanceof Sign) {
					if($sign->namedtag instanceof CompoundTag and isset($sign->namedtag->CellID)) {
						$uID = $sign->namedtag["CellID"];
						$cell = $this->getCellByID($uID);
						if($cell instanceof Cell) {
							$owner = $cell->getOwner();
							if($owner === null or $owner === "") {
								// OPEN
								$money = $player->getMoney();
								if(count($player->getCells()) < 1) {
									if($cell->getPrice() <= $money) {
										$player->reduceMoney($cell->getPrice());
										$text = $this->getConfig()->getNested("cells.messages.cell_rented");
										$text = str_replace(["{CELL_ID}", "{PRICE}"], [$uID, $cell->getPrice()], $text);
										$player->sendMessage($text);
										$cell->rent($player);
									} else {
										$player->sendMessage($this->getConfig()->getNested("cells.messages.cell_not_enough_money"));
									}
								} else {
									$player->sendMessage(TextFormat::GOLD . "- " . TextFormat::RED . " You're already renting a cell! Do /cell list to view your current cell.");
								}
								$event->setCancelled(true);
							} else {
								// RENTED
								$event->setCancelled(true);
								if(($p = $this->getServer()->getPlayerExact($owner)) instanceof Prisoner)
									$owner = $p->getName();
								if(!(floor(microtime(true) - $player->getLastCellSignTapTime()) <= 3 and $player->getLastCellSignTapped() === $sign)) {
									$cell = $this->getCellByID($uID);
									$text = $this->getConfig()->getNested("cells.messages.cell_info");
									$text = str_replace(["{CELL_ID}", "{OWNER}", "{EXPIRE}"], [
										$uID,
										$owner,
										$cell->getExpireString()
									], $text);
									$player->sendMessage($text);
									if($player->getCellByID($uID) instanceof Cell) {
										$time = time();
										$expireTime = $cell->getExpireTime();
										if($expireTime - $time <= 345600) {
											$player->sendMessage("\n" . TextFormat::RESET . TextFormat::GREEN . "Tap again to extend rent time for \${$cell->getPrice()}!" . TextFormat::RESET);
											$player->setLastCellSignTapTime();
											$player->setLastCellSignTapped($sign);
										} else {
											$player->sendMessage("\n" . TextFormat::RESET . TextFormat::GOLD . "You can extend your rent time in " . self::timeToString($expireTime - $time - 345600) . "!" . TextFormat::RESET);
										}
									}
								} else {
									if($cell->getPrice() <= $player->getMoney()) {
										$player->reduceMoney($cell->getPrice());
										$player->setLastCellSignTapped();
										$cell->setExpireTime($cell->getExpireTime() + 86400);
										$cell->updateConfig();
										$player->sendMessage(TextFormat::GOLD . "- " . TextFormat::GREEN . "You've successfully rented this cell for another day!");
									} else {
										$player->sendMessage(TextFormat::GOLD . "- " . TextFormat::RED . "You don't have enough money to extend your rent time!");
									}
								}
							}
						}
					}
					return;
				}
			}
			$item = $event->getItem();
			if($item->getId() === Item::BUCKET or $item->isHoe()) {
				$event->setCancelled(true);
				return;
			}
			$inside = $this->insideACell($event->getBlock());
			if($inside) {
				$cell = $this->getCellByID($inside);
				if($cell instanceof Cell) {
					if(!$player->isOp() && !$cell->isOwner($player)) {
						$event->setCancelled(true);
					}
				}
			}
		}
	}

	public function insideACell(Position $pos){
		foreach(self::$cells as $uID => $cell){
			if($cell instanceof Cell){
				if($cell->isInside($pos)){
					return $uID;
				}
			}
		}
		return false;
	}

	public function onBreak(BlockBreakEvent $event){
		$player = $event->getPlayer();
		if($player instanceof Prisoner){
			$inside = $this->insideACell($event->getBlock());
			if($inside){
				$cell = self::$cells[$inside];
				if($cell instanceof Cell){
					if(!$player->hasPermission("Cell.build") && !$cell->isOwner($player)){
						$event->setCancelled(true);
						$player->sendMessage($this->getConfig()->getNested("cells.messages.cell_cannot_build"));
					}elseif(!$cell->getFlag("edit")){
						$event->setCancelled(true);
						$player->sendMessage($this->getConfig()->getNested("cells.messages.cell_cannot_build"));
					}
				}
			}
		}
	}

	public function onPlace(BlockPlaceEvent $event){
		$player = $event->getPlayer();
		if($player instanceof Prisoner){
			$inside = $this->insideACell($event->getBlock());
			if($inside){
				$cell = self::$cells[$inside];
				if($cell instanceof Cell){
					if(!$player->hasPermission("Cell.build") && !$cell->isOwner($player)){
						$event->setCancelled(true);
						$player->sendMessage($this->getConfig()->getNested("cells.messages.cell_cannot_build"));
					}elseif(!$cell->getFlag("edit")){
						$event->setCancelled(true);
						$player->sendMessage($this->getConfig()->getNested("cells.messages.cell_cannot_build"));
					}
				}
			}
		}
	}

	public function onMove(PlayerMoveEvent $event){
		$p = $event->getPlayer();
		if(($result = $this->insideACell($p)) !== false) {
			$cell = self::$cells[$result];
			if($cell instanceof Cell and !$p->hasPermission("Cell.enter") && !$cell->isOwner($p) && !$cell->getFlag("public")){
				if(isset($this->movementCache[$p->getName()])){
					if($this->movementCache[$p->getName()] >= 3){
						$event->setTo(new Location($this->getConfig()->getNested("cells.lobby.x"), $this->getConfig()->getNested("cells.lobby.y"), $this->getConfig()->getNested("cells.lobby.z"), 0, 0, $this->getServer()->getLevelByName($this->getConfig()->getNested("cells.lobby.world"))));
						unset($this->movementCache[$p->getName()]);
					}else{
						$this->movementCache[$p->getName()]++;
					}
				}else{
					$this->movementCache[$p->getName()] = 0;
				}
				$event->setCancelled();
				$p->sendTip($this->getConfig()->getNested("cells.messages.cell_cannot_enter"));
			}
		}
	}

	/**
	 * @param PlayerCreationEvent $event
	 *
	 * @priority HIGHEST
	 */
	public function onCreation(PlayerCreationEvent $event){
		$event->setPlayerClass(Prisoner::class);
	}

	public function onJoin(PlayerJoinEvent $event){
		$p = $event->getPlayer();
		if($p instanceof Prisoner){
			$p->loadCells();
		}
	}

	public static function getExpireString(int $time){
		return self::timeToString($time - time());
	}
	public static function timeToString(int $value) {
		$days = floor($value / 86400);
		$hours = floor(($value % 86400) / 3600);
		$min = floor(($value % 3600) / 60);
		$sec = ($value % 60);

		return "$days days, $hours hours, $min min, $sec sec";
	}

}
