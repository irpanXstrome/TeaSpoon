<?php

/**
 *
 * MMP""MM""YMM               .M"""bgd
 * P'   MM   `7              ,MI    "Y
 *      MM  .gP"Ya   ,6"Yb.  `MMb.   `7MMpdMAo.  ,pW"Wq.   ,pW"Wq.`7MMpMMMb.
 *      MM ,M'   Yb 8)   MM    `YMMNq. MM   `Wb 6W'   `Wb 6W'   `Wb MM    MM
 *      MM 8M""""""  ,pm9MM  .     `MM MM    M8 8M     M8 8M     M8 MM    MM
 *      MM YM.    , 8M   MM  Mb     dM MM   ,AP YA.   ,A9 YA.   ,A9 MM    MM
 *    .JMML.`Mbmmd' `Moo9^Yo.P"Ybmmd"  MMbmmd'   `Ybmd9'   `Ybmd9'.JMML  JMML.
 *                                     MM
 *                                   .JMML.
 * This file is part of TeaSpoon.
 *
 * TeaSpoon is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * TeaSpoon is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with TeaSpoon.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @author CortexPE
 * @link https://CortexPE.xyz
 *
 */

declare(strict_types = 1);

// FYI: Event Priorities work this way: LOWEST -> LOW -> NORMAL -> HIGH -> HIGHEST -> MONITOR

namespace CortexPE;

use CortexPE\level\weather\Weather;
use CortexPE\utils\ArmorTypes;
use CortexPE\utils\Xp;
use pocketmine\block\Block;
use pocketmine\entity\Effect;
use pocketmine\entity\Entity;
use pocketmine\event\{
	block\BlockBreakEvent, level\LevelLoadEvent, Listener, server\RemoteServerCommandEvent, server\ServerCommandEvent
};
use pocketmine\event\entity\{
	EntityDamageEvent, EntityDeathEvent
};
use pocketmine\event\player\{
	cheat\PlayerIllegalMoveEvent, PlayerCommandPreprocessEvent, PlayerDropItemEvent, PlayerGameModeChangeEvent, PlayerInteractEvent, PlayerItemHeldEvent, PlayerKickEvent, PlayerLoginEvent, PlayerQuitEvent, PlayerRespawnEvent
};
use pocketmine\item\Armor;
use pocketmine\item\Item;
use pocketmine\level\Level;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\{
	EntityEventPacket, LevelEventPacket
};
use pocketmine\Player as PMPlayer;
use pocketmine\plugin\Plugin;
use pocketmine\Server as PMServer;

class EventListener implements Listener {

	const VERSION_COMMANDS = ["version", "ver", "about"];

	/** @var Plugin */
	public $plugin;

	public function __construct(Plugin $plugin){
		$this->plugin = $plugin;
	}

	/**
	 * @param LevelLoadEvent $ev
	 * @return bool
	 *
	 * @priority LOWEST
	 */
	public function onLevelLoad(LevelLoadEvent $ev){
		$TEMPORARY_ENTITIES = [
			Entity::XP_ORB,
			Entity::LIGHTNING_BOLT,
		];

		LevelManager::init();

		$lvl = $ev->getLevel();

		if(Main::$weatherEnabled){
			Main::$weatherData[$lvl->getId()] = new Weather($lvl, 0);
			if($lvl->getName() != Main::$netherName && $lvl->getName() != Main::$endName){
				Main::$weatherData[$lvl->getId()]->setCanCalculate(true);
			}else{
				Main::$weatherData[$lvl->getId()]->setCanCalculate(false);
			}
		}else{
			Main::$weatherData[$lvl->getId()] = new Weather($lvl, 0);
			Main::$weatherData[$lvl->getId()]->setCanCalculate(false);
		}

		foreach($lvl->getEntities() as $entity){
			if(in_array($entity::NETWORK_ID, $TEMPORARY_ENTITIES)){
				if(!$entity->isClosed()){
					$entity->close();
				}
			}
		}

		return true;
	}

	/**
	 * @param EntityDamageEvent $ev
	 * @return bool
	 *
	 * @priority HIGHEST
	 */
	public function onDamage(EntityDamageEvent $ev){
		if($ev->isCancelled()) return false;
		/////////////////////// TOTEM OF UNDYING ///////////////////////////////
		if(Main::$totemEnabled){
			if($ev->getDamage() >= $ev->getEntity()->getHealth()){
				$p = $ev->getEntity();
				if($p instanceof PMPlayer){
					if($p->getInventory()->getItemInHand()->getId() === Item::TOTEM && $ev->getCause() !== EntityDamageEvent::CAUSE_VOID && $ev->getCause() !== EntityDamageEvent::CAUSE_SUICIDE){
						$p->getInventory()->setItemInHand(Item::get(Item::AIR)); // this is supposed to be unstackable anyways... right?
						$ev->setCancelled(true);
						$p->setHealth(1);

						$p->removeAllEffects();

						$REGENERATION = Effect::getEffect(Effect::REGENERATION);
						$REGENERATION->setAmplifier(1);
						$REGENERATION->setVisible(true);
						$REGENERATION->setDuration(40 * 20);

						$ABSORPTION = Effect::getEffect(Effect::ABSORPTION);
						$ABSORPTION->setVisible(true);
						$ABSORPTION->setDuration(5 * 20);

						$FIRE_RESISTANCE = Effect::getEffect(Effect::FIRE_RESISTANCE);
						$FIRE_RESISTANCE->setVisible(true);
						$FIRE_RESISTANCE->setDuration(40 * 20);

						$p->addEffect($REGENERATION);
						$p->addEffect($ABSORPTION);
						$p->addEffect($FIRE_RESISTANCE);

						$pk = new LevelEventPacket();
						$pk->evid = LevelEventPacket::EVENT_SOUND_TOTEM;
						$pk->data = 0;
						$pk->position = new Vector3($p->getX(), $p->getY(), $p->getZ());
						PMServer::getInstance()->broadcastPacket($p->getViewers(), $pk);

						$pk2 = new EntityEventPacket();
						$pk2->entityRuntimeId = $p->getId();
						$pk2->event = EntityEventPacket::CONSUME_TOTEM;
						$pk2->data = 0;
						PMServer::getInstance()->broadcastPacket($p->getViewers(), $pk2);
					}
				}
			}
		}

		////////////////////////////// ARMOR DAMAGE //////////////////////////////////////
		$p = $ev->getEntity();
		if($p instanceof PMPlayer){
			$session = Main::getInstance()->getSessionById($p->getId());

			if($session instanceof Session){
				$session->useArmors(); // TODO: PMMP broke it. :cry:
			}
		}

		/////////////////////// ELYTRA WINGS & SLIME BLOCK ///////////////////////////////
		if($ev->getCause() === EntityDamageEvent::CAUSE_FALL){
			$p = $ev->getEntity();
			if($p instanceof PMPlayer){
				$session = Main::getInstance()->getSessionById($p->getId());
				if($session instanceof Session){
					if($session->isUsingElytra() || $p->getLevel()->getBlock($p->subtract(0, 1, 0))->getId() == Block::SLIME_BLOCK){
						$ev->setCancelled(true);
					}
				}
			}
		}

		return true;
	}

	/**
	 * @param PlayerRespawnEvent $ev
	 *
	 * @priority HIGHEST
	 */
	public function onRespawn(PlayerRespawnEvent $ev){
		if($ev->getPlayer()->isOnFire()) $ev->getPlayer()->setOnFire(0);
	}

	/**
	 * @param PlayerLoginEvent $ev
	 *
	 * @priority LOWEST
	 */
	public function onLogin(PlayerLoginEvent $ev){
		Main::getInstance()->createSession($ev->getPlayer());


		// derpy as fvck but this works...
		if(Main::$overworldLevelName != "" && !(Main::$overworldLevel instanceof Level) && PMServer::getInstance()->getDefaultLevel() instanceof Level){
			$orLvl = PMServer::getInstance()->getLevelByName(Main::$overworldLevelName);
			if($orLvl instanceof Level){
				Main::$overworldLevel = $orLvl;
			}else{
				Main::getInstance()->getLogger()->error("Overworld override Level does not exist. Falling back to default.");
				Main::$overworldLevel = PMServer::getInstance()->getDefaultLevel();
			}
		}else{
			Main::$overworldLevel = PMServer::getInstance()->getDefaultLevel();
		}
	}

	/**
	 * @param PlayerQuitEvent $ev
	 *
	 * @priority LOWEST
	 */
	public function onLeave(PlayerQuitEvent $ev){
		Main::getInstance()->destroySession($ev->getPlayer());
		unset(Main::$onPortal[$ev->getPlayer()->getId()]);
	}

	/**
	 * @param PlayerKickEvent $ev
	 *
	 * @priority HIGHEST
	 */
	public function onKick(PlayerKickEvent $ev){
		if($ev->isCancelled()) return;
		$p = $ev->getPlayer();
		if(!$p->isOnline()){
			return;
		}
		$pid = $p->getId();
		if($pid === null){
			return;
		}
		$session = Main::getInstance()->getSessionById($pid);
		if($session instanceof Session){
			if($session->isUsingElytra() && $ev->getReason() == PMServer::getInstance()->getLanguage()->translateString("kick.reason.cheat", ["%ability.flight"])){
				$ev->setCancelled(true);
			}
		}
	}

	/**
	 * @param PlayerIllegalMoveEvent $ev
	 *
	 * @priority LOWEST
	 */
	public function onCheat(PlayerIllegalMoveEvent $ev){
		$session = Main::getInstance()->getSessionById($ev->getPlayer()->getId());
		if($session instanceof Session){
			if($session->allowCheats){
				$ev->setCancelled();
			}
		}
	}

	/**
	 * @param EntityDeathEvent $ev
	 *
	 * @priority HIGHEST
	 */
	public function onEntityDeath(EntityDeathEvent $ev){
		if(Main::$dropMobExperience){
			$xp = Xp::getXpDropsForEntity($ev->getEntity());
			if($xp > 0){
				$ev->getEntity()->getLevel()->dropExperience($ev->getEntity()->asVector3(), $xp);
			}
		}
	}

	/**
	 * @param BlockBreakEvent $ev
	 *
	 * @priority HIGHEST
	 */
	public function onBlockBreak(BlockBreakEvent $ev){
		if($ev->isCancelled()) return;
		if(Main::$dropBlockExperience){
			$xp = Xp::getXpDropsForBlock($ev->getBlock());
			if($xp > 0){
				$ev->getPlayer()->getLevel()->dropExperience($ev->getBlock()->asVector3(), $xp);
			}
		}
	}

	/**
	 * @param PlayerCommandPreprocessEvent $ev
	 *
	 * @priority HIGHEST
	 */
	public function onPlayerCommandPreProcess(PlayerCommandPreprocessEvent $ev){
		if($ev->isCancelled()) return;
		if(in_array(substr($ev->getMessage(), 1), self::VERSION_COMMANDS) && !$ev->isCancelled()){
			$ev->setCancelled();
			Main::sendVersion($ev->getPlayer());
		}
	}

	/**
	 * @param ServerCommandEvent $ev
	 *
	 * @priority HIGHEST
	 */
	public function onServerCommand(ServerCommandEvent $ev){
		if($ev->isCancelled()) return;
		if(Utils::in_arrayi($ev->getCommand(), self::VERSION_COMMANDS) && !$ev->isCancelled()){
			$ev->setCancelled();
			Main::sendVersion($ev->getSender());
		}
	}

	/**
	 * @param RemoteServerCommandEvent $ev
	 *
	 * @priority HIGHEST
	 */
	public function onRemoteServerCommand(RemoteServerCommandEvent $ev){
		if($ev->isCancelled()) return;
		if(Utils::in_arrayi($ev->getCommand(), self::VERSION_COMMANDS) && !$ev->isCancelled()){
			$ev->setCancelled();
			Main::sendVersion($ev->getSender());
		}
	}

	/**
	 * @param PlayerItemHeldEvent $ev
	 *
	 * @priority HIGHEST
	 */
	public function onItemHeld(PlayerItemHeldEvent $ev){
		if($ev->isCancelled()) return;
		$session = Main::getInstance()->getSessionById($ev->getPlayer()->getId());
		if($session instanceof Session){
			if($session->fishing){
				if($ev->getSlot() != $session->lastHeldSlot){
					$session->unsetFishing();
				}
			}

			$session->lastHeldSlot = $ev->getSlot();
		}
	}

	/**
	 * @param PlayerInteractEvent $ev
	 *
	 * @priority HIGHEST
	 */
	public function onInteract(PlayerInteractEvent $ev){
		if($ev->isCancelled()) return;
		if(Main::$instantArmorEnabled){
			// MCPE(BE) does this client-side... we just have to do the same server-side.
			$item = clone $ev->getItem();
			$player = $ev->getPlayer();
			$check = ($ev->getAction() == PlayerInteractEvent::RIGHT_CLICK_BLOCK || $ev->getAction() == PlayerInteractEvent::RIGHT_CLICK_AIR);
			$isBlocked = (in_array($ev->getBlock()->getId(), [
				Block::ITEM_FRAME_BLOCK,
			]));

			if($check && !$isBlocked){
				if($ev->getItem() instanceof Armor){
					$inventory = $player->getArmorInventory();
					$type = ArmorTypes::getType($item);
					$old = Item::get(Item::AIR, 0, 1); // just a placeholder
					$skipReplace = false;
					if($type !== ArmorTypes::TYPE_NULL){
						switch($type){
							case ArmorTypes::TYPE_HELMET:
								$old = clone $inventory->getHelmet();
								if(!Main::$instantArmorReplace && !$old->isNull()){
									$skipReplace = true;
									break;
								}
								$inventory->setHelmet($item);
								break;
							case ArmorTypes::TYPE_CHESTPLATE:
								$old = clone $inventory->getChestplate();
								if(!Main::$instantArmorReplace && !$old->isNull()){
									$skipReplace = true;
									break;
								}
								$inventory->setChestplate($item);
								break;
							case ArmorTypes::TYPE_LEGGINGS:
								$old = clone $inventory->getLeggings();
								if(!Main::$instantArmorReplace && !$old->isNull()){
									$skipReplace = true;
									break;
								}
								$inventory->setLeggings($item);
								break;
							case ArmorTypes::TYPE_BOOTS:
								$old = clone $inventory->getBoots();
								if(!Main::$instantArmorReplace && !$old->isNull()){
									$skipReplace = true;
									break;
								}
								$inventory->setBoots($item);
								break;
						}
						if(!$skipReplace){
							if(!Main::$instantArmorReplace){
								if($player->isSurvival() || $player->isAdventure()){
									$player->getInventory()->setItemInHand(Item::get(Item::AIR, 0, 1));
								}
							}else{
								if(!$old->isNull()){
									$player->getInventory()->setItemInHand($old);
								}else{
									$player->getInventory()->setItemInHand(Item::get(Item::AIR, 0, 1));
								}
							}
						}
					}
				}
			}
		}
	}

	/**
	 * @param PlayerGameModeChangeEvent $ev
	 *
	 * @priority HIGHEST
	 */
	public function onGameModeChange(PlayerGameModeChangeEvent $ev){
		if(!$ev->isCancelled()){
			if(Main::$clearInventoryOnGMChange){
				$ev->getPlayer()->getInventory()->clearAll();
			}
		}
	}

	/**
	 * @param PlayerDropItemEvent $ev
	 *
	 * @priority HIGHEST
	 */
	public function onPlayerDropItem(PlayerDropItemEvent $ev){
		if(!$ev->isCancelled() && Main::$limitedCreative && $ev->getPlayer()->isCreative()){
			$ev->setCancelled();
		}
	}
}
