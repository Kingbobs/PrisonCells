<?php

namespace PrisonCells;

use core\CorePlayer;
use onebone\economyapi\EconomyAPI;
use pocketmine\tile\Sign;

class Prisoner extends CorePlayer{

	protected $cells = [];
	protected $lastCellSignTapTime = 0;
	protected $lastCellSignTapped = null;

	public function getCells(){
		return $this->cells;
	}

	public function setCells(array $cells){
		$this->cells = $cells;
	}

	public function getCellByID($uID){
		if(isset($this->cells[$uID])) return $this->cells[$uID];
		return null;
	}

	public function getMoney(){
		return EconomyAPI::getInstance()->myMoney($this);
	}

	public function reduceMoney($amount){
		EconomyAPI::getInstance()->reduceMoney($this, $amount);
	}

	public function addCell(Cell $cell){
		$this->cells[$cell->getID()] = $cell;
	}

	public function removeCell(Cell $cell){
		unset($this->cells[$cell->getID()]);
	}

	public function loadCells(){
		foreach(PrisonCells::$cells as $cell){
			if($cell->getOwner() === strtolower($this->getName())) $this->addCell($cell);
		}
	}

	public function getLastCellSignTapTime() : int {
		return $this->lastCellSignTapTime;
	}

	public function setLastCellSignTapTime() {
		$this->lastCellSignTapTime = microtime(true);
	}

	public function getLastCellSignTapped() {
		return $this->lastCellSignTapped;
	}

	public function setLastCellSignTapped(Sign $sign = null) {
		$this->lastCellSignTapped = $sign;
	}
}
