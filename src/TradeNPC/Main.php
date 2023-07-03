<?php

declare(strict_types=1);

namespace TradeNPC;

use muqsit\invmenu\InvMenu;
use muqsit\invmenu\InvMenuHandler;
use muqsit\invmenu\transaction\InvMenuTransaction as Transaction;
use muqsit\invmenu\transaction\InvMenuTransactionResult as TransactionResult;
use muqsit\simplepackethandler\SimplePacketHandler;
use pocketmine\block\utils\DyeColor;
use pocketmine\block\VanillaBlocks;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\entity\EntityDataHelper;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\Human;
use pocketmine\entity\Location;
use pocketmine\event\inventory\InventoryOpenEvent;
use pocketmine\event\Listener;
use pocketmine\item\VanillaItems;
use pocketmine\nbt\tag\ByteArrayTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\ContainerClosePacket;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\SingletonTrait;
use pocketmine\world\World;
use TradeNPC\entity\TradeNPC;
use TradeNPC\inventory\TradeInventory;
use TradeNPC\listener\EventListener;

class Main extends PluginBase implements Listener {

    use SingletonTrait;

	public $currentWindow = null;

	public $fullItem = [];

	public InvMenu $menu;

    public $name = null;

    public $start = false;

    public $turn = false;

	protected function onLoad(): void {
        self::setInstance($this);
	}

	protected function onEnable(): void {
        if (!InvMenuHandler::isRegistered()) {
            InvMenuHandler::register($this);
        }
		EntityFactory::getInstance()->register(TradeNPC::class, function (World $world, CompoundTag $nbt): TradeNPC {
			return new TradeNPC(EntityDataHelper::parseLocation($nbt, $world), Human::parseSkinNBT($nbt), $nbt);
		}, ['TradeNPC', 'Trade']);
		$this->getServer()->getPluginManager()->registerEvents(new EventListener($this), $this);
        SimplePacketHandler::createInterceptor($this)
            ->interceptIncoming(static function (ContainerClosePacket $packet, NetworkSession $networkSession): bool {
                $player = $networkSession->getPlayer();
                if (isset(TradeDataPool::$windowIdData[$player->getName()])) {
                    $pk = ContainerClosePacket::create(
                        windowId: 255,
                        server: false
                    );
                    $networkSession->sendDataPacket($pk);
                }
                return true;
            });
		$this->menu = InvMenu::create(InvMenu::TYPE_CHEST);
	}

	public function getEntityName(string $chat) {
		foreach ($this->getServer()->getWorldManager()->getWorlds() as $level) {
			foreach ($level->getEntities() as $entity) {
				if ($entity instanceof TradeNPC) {
					if ($entity->getNameTag() === $chat) {
						$this->turn = false;
						return $entity;
					}
				}
			}
		}
	}

	public function setItems($p) {
		$this->menu->setName("§eTradeNPC");
		$this->menu->setListener(function (Transaction $transaction): TransactionResult {
			$player = $transaction->getPlayer();
			if ($transaction->getItemClicked()->getTypeId() === VanillaBlocks::STAINED_GLASS_PANE()->asItem()->getTypeId()) {
				foreach (range(0, 26) as $slot) {
					$item = $this->menu->getInventory()->getItem($slot);
					if ($item->getBlock()->isSameState(VanillaBlocks::STAINED_GLASS_PANE())) {
						continue;
					}
					$this->fullItem[] = $item;
				}
				$this->turn = true;
				$this->name = $player->getName();
                $player->removeCurrentWindow();
				$player->sendMessage("Input name npc to chat");
				return $transaction->discard();
			}
			return $transaction->continue();
		});
		$confirm = VanillaBlocks::STAINED_GLASS_PANE()->setColor(DyeColor::GRAY())->asItem();
        $confirm->setCustomName("Confirm\nAfter choose, input name of npc to chat");
		$exit = VanillaItems::REDSTONE_DUST();
        $exit->setCustomName("Exit");
		$inv = $this->menu->getInventory();
		$inv->setItem(26, $confirm);
		$this->menu->send($p);
	}

	public function saveAll() {
		foreach ($this->getServer()->getOnlinePlayers() as $player) {
			$player->save();
		}
		foreach ($this->getServer()->getWorldManager()->getWorlds() as $world) {
			$world->save(true);
		}
	}

	public function distance($posX, $posZz, $X, $Z): float {
		return sqrt($this->distanceSquared($posX, $posZz, $X, $Z));
	}

	public function distanceSquared($posx, $posz, $x, $z): float {
		return (($x - $posx) ** 2) + (($z - $posz) ** 2);
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
		if (!$sender instanceof Player) {
			return true;
		}
		if (!isset($args[0])) {
			$args[0] = "x";
		}
		if ($this->getServer()->isOp($sender->getName())) {
			switch ($args[0]) {
				case "create":
					array_shift($args);
					$name = implode(" ", $args);
					if (!isset($name)) {
						$sender->sendMessage("Usage: /npc create (name)");
						break;
					}
					$nbt = CompoundTag::create();
					$nbt->setTag("Name", new StringTag($sender->getSkin()->getSkinId()));
					$nbt->setTag("Data", new ByteArrayTag($sender->getSkin()->getSkinData()));
					$nbt->setTag("CapeData", new ByteArrayTag($sender->getSkin()->getCapeData()));
					$nbt->setTag("GeometryName", new StringTag($sender->getSkin()->getGeometryName()));
					$nbt->setTag("GeometryData", new ByteArrayTag($sender->getSkin()->getGeometryData()));
					$entity = new TradeNPC(
                        Location::fromObject(
                            $sender->getPosition()->add(0.5, 0, 0.5),
                            $sender->getPosition()->getWorld(),
                                $sender->getLocation()->getYaw() ?? 0,
                                $sender->getLocation()->getPitch() ?? 0
                        ),
                        $sender->getSkin(),
                        $nbt
                    );
					$entity->setNameTag($name);
					$entity->setNameTagAlwaysVisible();
					$entity->spawnToAll();
					break;
				case "setitem":
					$this->setItems($sender);
					break;
				case "remove":
					array_shift($args);
					$name = implode(" ", $args);
					if (!isset($name)) {
						$sender->sendMessage("Usage: /npc remove (name)");
						break;
					}
					if (!file_exists($this->getDataFolder() . $name . ".dat")) {
						$sender->sendMessage("Npc not found!");
						break;
					}
					unlink($this->getDataFolder() . $name . ".dat");
					$sender->sendMessage("Deleted NPC!");
					$this->saveall();
					foreach ($this->getServer()->getWorldManager()->getWorlds() as $level) {
						foreach ($level->getEntities() as $entity) {
							if ($entity instanceof TradeNPC) {
								if ($entity->getNameTag() === $name) {
									$entity->close();
									break;
								}
							}
						}
					}
					break;
				default:
					foreach ([
						["/npc create (name)", "Create npc trade"],
						["/npc setitem", "Add item to trade"],
						["/npc remove", "Delete npc"]
					] as $usage) {
						$sender->sendMessage($usage[0] . " - " . $usage[1]);
					}
			}
		}
		return true;
	}

	public function setCWindow(TradeInventory $inventory, $player): bool {
		if ($inventory === $this->currentWindow) {
			return true;
		}
		$ev = new InventoryOpenEvent($inventory, $player);
		$ev->call();
		if ($ev->isCancelled()) {
			return false;
		}
		$player->removeCurrentWindow();

		if ($player->getNetworkSession()->getInvManager() === null) {
			throw new \InvalidArgumentException("Player cannot open inventories in this state");
		}
		$inventory->onOpen($player);
		$this->currentWindow = $inventory;
		return true;
	}
}
