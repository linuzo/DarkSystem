<?php

#______           _    _____           _                  
#|  _  \         | |  /  ___|         | |                 
#| | | |__ _ _ __| | _\ `--. _   _ ___| |_ ___ _ __ ___   
#| | | / _` | '__| |/ /`--. \ | | / __| __/ _ \ '_ ` _ \  
#| |/ / (_| | |  |   </\__/ / |_| \__ \ ||  __/ | | | | | 
#|___/ \__,_|_|  |_|\_\____/ \__, |___/\__\___|_| |_| |_| 
#                             __/ |                       
#                            |___/

namespace pocketmine\entity;

use pocketmine\entity\monster\Monster;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\level\Level;
use pocketmine\math\Math;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\network\protocol\AddEntityPacket;
use pocketmine\Player;
use pocketmine\level\Location;
use pocketmine\level\Position;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\FloatTag;

abstract class BaseEntity extends Creature{

	protected $moveTime = 0;
	
	protected $baseTarget = null;

	private $movement = true;
	private $friendly = false;
	private $wallcheck = true;
	
	protected $sprintTime = 0;
	
	protected $speed = 1;
	
	private static $closeMonsterOnDay = false;
	
	public static function setCloseMonsterOnDay($val){
		BaseEntity::$closeMonsterOnDay = $val;
	}

	public function __destruct(){}

	public abstract function updateMove();

	public function getSaveId(){
		$class = new \ReflectionClass(get_class($this));
		return $class->getShortName();
	}

	public function isMovement(){
		return $this->movement;
	}

	public function isFriendly(){
		return $this->friendly;
	}

	public function isKnockback(){
		return $this->attackTime > 0;
	}

	public function isWallCheck(){
		return $this->wallcheck;
	}

	public function setMovement($value){
		$this->movement = $value;
	}

	public function setFriendly($bool){
		$this->friendly = $bool;
	}

	public function setWallCheck($value){
		$this->wallcheck = $value;
	}

	public function getSpeed(){
		return 1.0;
	}

	public function initEntity(){
		parent::initEntity();

		if(isset($this->namedtag->Movement)){
			$this->setMovement($this->namedtag["Movement"]);
		}

		if(isset($this->namedtag->WallCheck)){
			$this->setWallCheck($this->namedtag["WallCheck"]);
		}
		$this->dataProperties[BaseEntity::DATA_NO_AI] = [BaseEntity::DATA_TYPE_BYTE, 1];
	}

	public function saveNBT(){
		parent::saveNBT();
		$this->namedtag->Movement = new ByteTag("Movement", $this->isMovement());
		$this->namedtag->WallCheck = new ByteTag("WallCheck", $this->isWallCheck());
	}

	public function spawnTo(Player $player){
		if(
			!isset($this->hasSpawned[$player->getId()])
			&& isset($player->usedChunks[Level::chunkHash($this->chunk->getX(), $this->chunk->getZ())])
		){
			$pk = new AddEntityPacket();
			$pk->eid = $this->getID();
			$pk->type = static::NETWORK_ID;
			$pk->x = $this->x;
			$pk->y = $this->y;
			$pk->z = $this->z;
			$pk->speedX = 0;
			$pk->speedY = 0;
			$pk->speedZ = 0;
			$pk->yaw = $this->yaw;
			$pk->pitch = $this->pitch;
			$pk->metadata = $this->dataProperties;
			$player->dataPacket($pk);

			$this->hasSpawned[$player->getId()] = $player;
		}
	}

	public function updateMovement(){
		if(
			$this->lastX !== $this->x
			|| $this->lastY !== $this->y
			|| $this->lastZ !== $this->z
			|| $this->lastYaw !== $this->yaw
			|| $this->lastPitch !== $this->pitch
		){
			$this->lastX = $this->x;
			$this->lastY = $this->y;
			$this->lastZ = $this->z;
			$this->lastYaw = $this->yaw;
			$this->lastPitch = $this->pitch;
		}
		$this->level->addEntityMovement($this->getViewers(), $this->id, $this->x, $this->y, $this->z, $this->yaw, $this->pitch);
	}

	public function isInsideOfSolid(){
		$block = $this->level->getBlock(new Vector3(Math::floorFloat($this->x), Math::floorFloat($this->y + $this->height - 0.18), Math::floorFloat($this->z)));
		$bb = $block->getBoundingBox();
		return $bb !== null and $block->isSolid() and !$block->isTransparent() and $bb->intersectsWith($this->getBoundingBox());
	}

	public function attack($damage, EntityDamageEvent $source){
		if($this->isKnockback() > 0) return;

		parent::attack($damage, $source);

		if($source->isCancelled() || !($source instanceof EntityDamageByEntityEvent)){
			return;
		}

		$damager = $source->getDamager();
		$motion = (new Vector3($this->x - $damager->x, $this->y - $damager->y, $this->z - $damager->z))->normalize();
		$this->motionX = $motion->x * 0.19;
		$this->motionZ = $motion->z * 0.19;
		if(!($this instanceof Monster) || $this->isFriendly()){
			$this->sprintTime = mt_rand(60, 120);
			$this->moveTime = 0;
		}
		if($this instanceof FlyingEntity){
			$this->motionY = $motion->y * 0.19;
		}else{
			$this->motionY = 0.5;
		}
	}

	public function knockBack(Entity $attacker, $damage, $x, $z, $base = 0.4){

	}

	public function entityBaseTick($tickDiff = 1){
		$hasUpdate = Entity::entityBaseTick($tickDiff);
		if($this->isInsideOfSolid()){
			$hasUpdate = true;
			$ev = new EntityDamageEvent($this, EntityDamageEvent::CAUSE_SUFFOCATION, 1);
			$this->attack($ev->getFinalDamage(), $ev);
		}
		if($this->moveTime > 0){
			$this->moveTime -= $tickDiff;
		}
		 if($this->sprintTime > 0){
			$this->sprintTime -= $tickDiff;
		}
		if($this->attackTime > 0){
			$this->attackTime -= $tickDiff;
		}
		return $hasUpdate;
	}

	public function move($dx, $dy, $dz){
		$list = $this->level->getCollisionCubes($this, $this->level->getServer()->getTick() > 1 ? $this->boundingBox->getOffsetBoundingBox($dx, $dy, $dz) : $this->boundingBox->addCoord($dx, $dy, $dz));
		if($this->isWallCheck()){
			foreach($list as $bb){
				$dx = $bb->calculateXOffset($this->boundingBox, $dx);
			}
			$this->boundingBox->offset($dx, 0, 0);
			foreach($list as $bb){
				$dz = $bb->calculateZOffset($this->boundingBox, $dz);
			}
			$this->boundingBox->offset(0, 0, $dz);
		}
		foreach($list as $bb){
			$dy = $bb->calculateYOffset($this->boundingBox, $dy);
		}
		$this->boundingBox->offset(0, $dy, 0);
		$this->setComponents($this->x + $dx, $this->y + $dy, $this->z + $dz);
		$this->checkChunks();
		return true;
	}

	public function targetOption(Creature $creature, $distance){
		return $this instanceof Monster && (!($creature instanceof Player) || ($creature->isSurvival() || $creature->isAdventure() && $creature->spawned)) && $creature->isAlive() && !$creature->closed && $distance <= 51;
	}
	
	public static function create($type, Position $source, ...$args){
		$nbt = new CompoundTag("", [
			"Pos" => new ListTag("Pos", [
				new DoubleTag("", $source->x),
				new DoubleTag("", $source->y),
				new DoubleTag("", $source->z)
			]),
			"Motion" => new ListTag("Motion", [
				new DoubleTag("", 0),
				new DoubleTag("", 0),
				new DoubleTag("", 0)
			]),
			"Rotation" => new ListTag("Rotation", [
				new FloatTag("", $source instanceof Location ? $source->yaw : 0),
				new FloatTag("", $source instanceof Location ? $source->pitch : 0)
			]),
		]);
		return Entity::createEntity($type, $source->getLevel(), $nbt, ...$args);
	}
	
	public function isNeedSaveOnChunkUnload(){
		return true;
	}
	
}
