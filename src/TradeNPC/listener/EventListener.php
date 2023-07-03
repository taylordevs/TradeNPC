<?php

declare(strict_types=1);

namespace TradeNPC\listener;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\item\Item;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\JwtUtils;
use pocketmine\network\mcpe\protocol\ActorEventPacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\mcpe\protocol\types\ActorEvent;
use pocketmine\network\mcpe\protocol\types\inventory\NetworkInventoryAction;
use pocketmine\network\mcpe\protocol\types\inventory\NormalTransactionData;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemOnEntityTransactionData;
use pocketmine\player\Player;
use TradeNPC\entity\TradeNPC;
use TradeNPC\Main;
use TradeNPC\TradeDataPool;

class EventListener implements Listener
{

    protected $deviceOSData = [];

    public function __construct(
        protected Main $plugin
    )
    {
    }

    public function onChat(PlayerChatEvent $event): void
    {
        $player = $event->getPlayer();
        $chat = $event->getMessage();
        $plugin = $this->plugin;
        if ($plugin->turn and $plugin->name == $player->getName()) {
            $entity = $plugin->getEntityName($chat);
            if ($entity === null) {
                return;
            }
            for ($i = 0; $i <= $plugin->fullItem; $i++) {
                $item1 = $plugin->fullItem[$i];
                $item2 = $plugin->fullItem[$i + 9];
                $item3 = $plugin->fullItem[$i + 18];
                if ($item1->isNull() or $item2->isNull() or $item3->isNull()) {
                    unset(TradeDataPool::$editNPCData[$player->getName()]);
                    break;
                }
                TradeDataPool::$editNPCData[$player->getName()]["buyA"] = $item1;
                TradeDataPool::$editNPCData[$player->getName()]["buyB"] = $item2;
                TradeDataPool::$editNPCData[$player->getName()]["sell"] = $item3;
                $buyA = TradeDataPool::$editNPCData[$player->getName()]["buyA"];
                $buyB = TradeDataPool::$editNPCData[$player->getName()]["buyB"];
                $sell = TradeDataPool::$editNPCData[$player->getName()]["sell"];
                $entity->addTradeItem($buyA, $buyB, $sell);
                unset(TradeDataPool::$editNPCData[$player->getName()]);
                $player->sendMessage("Added item to trade!");
                $plugin->saveAll();
            }
            $plugin->fullItem = [];
            $plugin->turn = false;
            $plugin->name = null;
            unset(TradeDataPool::$editNPCData[$player->getName()]);
            $event->cancel();
        }
    }

    public function handleDataPacket(DataPacketReceiveEvent $event): void
    {
        $plugin = $this->plugin;
        $player = $event->getOrigin()->getPlayer();
        $packet = $event->getPacket();
        if ($player instanceof Player and isset(TradeDataPool::$windowIdData[$player->getName()])) {
            var_dump(TradeDataPool::$windowIdData[$player->getName()]);
        }
        if ($packet instanceof ActorEventPacket) {
            if ($packet->eventId === ActorEvent::COMPLETE_TRADE) {
                if (!isset(TradeDataPool::$interactNPCData[$player->getName()])) {
                    return;
                }
                $data = TradeDataPool::$interactNPCData[$player->getName()]->getShopCompoundTag()->getListTag("Recipes")->get($packet->eventData);
                if ($data instanceof CompoundTag) {
                    $buyA = Item::nbtDeserialize($data->getCompoundTag("buyA"));
                    $buyB = Item::nbtDeserialize($data->getCompoundTag("buyB"));
                    $sell = Item::nbtDeserialize($data->getCompoundTag("sell"));
                    if (
                        $player->getInventory()->contains($buyA) and
                        $player->getInventory()->contains($buyB)
                    ) {
                        $player->getInventory()->removeItem($buyA);
                        $player->getInventory()->removeItem($buyB);
                        $player->getInventory()->addItem($sell);
                    }
                }
            }
        }
        if ($packet instanceof InventoryTransactionPacket) {
            if ($packet->trData instanceof NormalTransactionData) {
                foreach ($packet->trData->getActions() as $action) {
                    if ($action instanceof NetworkInventoryAction) {
                        if (
                            isset(TradeDataPool::$windowIdData[$player->getName()]) and
                            $action->windowId === TradeDataPool::$windowIdData[$player->getName()]
                        ) {
                            $player->getInventory()->addItem($action->oldItem);
                            $player->getInventory()->removeItem($action->newItem);
                        }
                    }
                }
            } elseif ($packet->trData instanceof UseItemOnEntityTransactionData) {
                $entity = $player->getWorld()->getEntity($packet->trData->getActorRuntimeId());
                if ($entity instanceof TradeNPC) {
                    $plugin->setCWindow($entity->getTradeInventory(), $player);
                }
            }
        }
        if ($packet instanceof LoginPacket) {
            $data = JwtUtils::parse($packet->clientDataJwt);
            $device = intval($data[1]["DeviceOS"]);
            $this->deviceOSData[strtolower($data[1]["ThirdPartyName"])] = $device;
        }
    }

    public function onQuit(PlayerQuitEvent $event)
    {
        $player = $event->getPlayer();
        if (isset($this->deviceOSData[strtolower($player->getName())]))
            unset($this->deviceOSData[strtolower($player->getName())]);
    }
}