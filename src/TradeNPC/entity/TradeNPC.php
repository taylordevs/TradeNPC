<?php

declare(strict_types=1);

namespace TradeNPC\entity;

use pocketmine\entity\Ageable;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Human;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\TreeRoot;
use TradeNPC\inventory\TradeInventory;

class TradeNPC extends Human implements Ageable
{

    protected $shop = null;

    public function initEntity(CompoundTag $nbt): void
    {
        parent::initEntity($nbt);
        if ($this->shop === null && $nbt->getTag("shop") !== null) {
            $this->shop = (new LittleEndianNbtSerializer)->read($nbt->getString("shop"));
        } else {
            $this->shop = CompoundTag::create()
                ->setTag("Recipes", new ListTag([], NBT::TAG_Compound));
        }
        $this->setNameTagAlwaysVisible(true);
    }

    public function saveNBT(): CompoundTag
    {
        $nbt = parent::saveNBT();
        if ($this->shop !== null) {
            $nbt->setString("shop", $this->getSaveNBT());
        }
        return $nbt;
    }

    public function getSaveNBT(): string
    {
        return (new LittleEndianNbtSerializer)->write($this->getTreeRoot($this->shop));
    }

    public function getTreeRoot($tag)
    {
        if ($tag instanceof CompoundTag) {
            return new TreeRoot($tag);
        } elseif ($tag instanceof TreeRoot) {
            return $tag;
        }
    }

    public function getName(): string
    {
        return "TradeNPC";
    }

    public function isBaby(): bool
    {
        return false;
    }

    public function addTradeItem(Item $buyA, Item $buyB, Item $sell): void
    {
        $this->getTagFunction($this->shop)->getListTag("Recipes")->push($this->makeRecipe($buyA, $buyB, $sell));
    }

    public function getTagFunction($tag)
    {
        return (
            $tag instanceof TreeRoot &&
            $tag->getTag() instanceof CompoundTag
        ) ? $tag->getTag() : $tag;
    }

    public function makeRecipe(Item $buyA, Item $buyB, Item $sell): CompoundTag
    {
        return CompoundTag::create()
            ->setTag("buyA", $buyA->nbtSerialize())
            ->setTag("buyB", $buyB->nbtSerialize())
            ->setTag("sell", $sell->nbtSerialize())
            ->setInt("maxUses", 32767)
            ->setByte("rewardExp", 0)
            ->setInt("uses", 0)
            ->setString("label", "");
    }

    public function getShopCompoundTag(): ?CompoundTag
    {
        return ($this->shop instanceof TreeRoot) ? $this->shop->mustGetCompoundTag() : $this->shop;
    }

    public function getTradeInventory(): TradeInventory
    {
        return new TradeInventory($this);
    }

    public function attack(EntityDamageEvent $source): void
    {
        $source->cancel();
    }

    protected function getInitialSizeInfo(): EntitySizeInfo
    {
        return new EntitySizeInfo(1.8, 0.6);
    }
}
