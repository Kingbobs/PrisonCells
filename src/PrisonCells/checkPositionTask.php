<?php

namespace PrisonCells;

use pocketmine\level\Position;
use pocketmine\scheduler\PluginTask;

class checkPositionTask extends PluginTask{

	public function __construct(PrisonCells $owner){
		parent::__construct($owner);
	}

	public function onRun($currentTick){
		foreach($this->getOwner()->getServer()->getOnlinePlayers() as $player){
			if($player instanceof Prisoner){
				$inside = PrisonCells::getInstance()->insideACell($player);
				if($inside){
					$cell = PrisonCells::$cells[$inside];
					if($cell instanceof Cell){
						if(!$player->hasPermission("Cell.enter") && !$cell->isOwner($player) && !$cell->getFlag("public")){
							$player->teleport(new Position($this->getOwner()->getConfig()->getNested("cells.lobby.x"), $this->getOwner()->getConfig()->getNested("cells.lobby.l"), $this->getOwner()->getConfig()->getNested("cells.lobby.z"), $this->getOwner()->getServer()->getLevelByName($this->getOwner()->getConfig()->getNested("cells.lobby.world"))));
							$player->sendMessage(PrisonCells::getInstance()->getConfig()->getNested("cells.messages.cell_cannot_enter"));
						}
					}
				}
			}
		}
	}
}
