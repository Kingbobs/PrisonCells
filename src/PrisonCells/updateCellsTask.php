<?php

namespace PrisonCells;

use pocketmine\scheduler\PluginTask;

class updateCellsTask extends PluginTask{

	public function __construct(PrisonCells $owner){
		parent::__construct($owner);
	}

	public function onRun($currentTick){
		foreach(PrisonCells::$cells as $uID => $cell){
			if($cell instanceof Cell){
				if($cell->expired()){
					$cell->reset();
				}
			}
		}
	}
}
