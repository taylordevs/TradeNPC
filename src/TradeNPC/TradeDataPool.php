<?php

declare(strict_types=1);

namespace TradeNPC;

use TradeNPC\entity\TradeNPC;

class TradeDataPool
{

    /** @var TradeNPC[] */
    public static $interactNPCData = [];

    /** @var int[] */
    public static $windowIdData = [];

    public static $editNPCData = [];
}
