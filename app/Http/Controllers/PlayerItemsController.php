<?php

namespace App\Http\Controllers;

use App\Models\PlayerItems;
use App\Models\Player;
use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class PlayerItemsController extends Controller
{
    //アイテムの所持処理
    public function addItem(Request $request, $id)
    {
        // プレイヤーIDとアイテムIDでレコードをデータベースから検索
        $playerItem = PlayerItems::where('player_id', $id)
            ->where('item_id', $request->itemId)
            ->lockForUpdate() // ここでロックを取得
            ->first();

        if (!$playerItem) {
            // アイテムが存在しない場合、新しいレコードを挿入
            $playerItem = new PlayerItems([
                'player_id' => $id,
                'item_id' => $request->itemId,
                'count' => $request->count,
            ]);

            // データベースに保存
            $playerItem->save();

            // レスポンスを返す
            return Response() -> json(['itemId' => $request->itemId,
            'count' => $request->count]);
        }

        // 既存のアイテムが存在する場合、countを加算
        $playerItem->count += $request->count;

        // データベースに保存
        $playerItem->save();

        // レスポンスを返す
        return Response() -> json(['itemId' => $request->itemId,
            'count' => $playerItem->count]);
    }

    //アイテムの使用処理
    public function useItem(Request $request, $id)
    {
        // プレイヤーIDとアイテムIDでレコードをデータベースから検索
        $playerItem = PlayerItems::where('player_id', $id)
            ->where('item_id', $request->itemId)
            ->first();

        // アイテムの所持数がゼロ && アイテムが存在しない場合はエラーレスポンスを返す
        if (!$playerItem || $playerItem->count <= 0) {
            return response()->json(['error' => 'No items remaining'], 400);
        }

        // HPとMPの上限は200
        $maxHp = 200;
        $maxMp = 200;

        // プレイヤーのステータスを取得
        $player = Player::find($id);

        // アイテムごとの処理
        if ($request->itemId == 1) 
        { // HPかいふく薬
            // アイテムの値を取得
            $itemValue = Item::where('id', $request->itemId)->value('value');

            // HP増加処理
            if($player->hp < $maxHp)// HPが上限に達していない場合のみ処理
            {
                $newHp = min($maxHp, $player->hp + $itemValue);

                $player->hp = $newHp;
                $playerItem->count -= 1;
            }
        } 
        elseif ($request->itemId == 2) 
        { // MPかいふく薬
            // アイテムの値を取得
            $itemValue = Item::where('id', $request->itemId)->value('value');

            // MP増加処理
            if($player->mp < $maxMp)// MPが上限に達していない場合のみ処理
            {
                $newMp = min($maxMp, $player->mp + $itemValue);

                $player->mp = $newMp;
                $playerItem->count -= 1;
            }
        } 
        else
        {
            // 不明なアイテムの場合はエラーレスポンスを返す
            return response()->json(['error' => 'Unknown item'], 400);
        }

        // プレイヤーのステータスを保存
        $player->save();
        $playerItem->save();

        // レスポンスを返す
        return response()->json([
            'itemId' => $request->itemId,
            'count' => $playerItem->count,
            'player' => [
                'id' => $player->id,
                'hp' => $player->hp,
                'mp' => $player->mp,
            ],
        ]);
    }
    
    //ガチャの使用処理
    public function useGacha(Request $request, $id)
    {
        // プレイヤーの存在確認
        $player = Player::findOrFail($id);

        // 所持金の確認
        $gachaCount = $request->input('count');
        $gachaCost = 10;
        $totalCost = $gachaCount * $gachaCost;

        if ($player->money < $totalCost) {
            return response()->json(['error' => 'Not enough money to perform Gacha.'], 400);
        }

        // ガチャを引く
        $gachaResults = $this->performGacha($gachaCount);

        // 所持金の更新
        $player->money -= $totalCost;
        $player->save();

        // アイテムの更新
        //アイテムの更新
        $updatedItems = $this->updatePlayerItems($player, $gachaResults);

        // レスポンスを返す
        return response()->json([
            'results' => $gachaResults,
            'player' => [
                'money' => $player->money,
                'items' => $updatedItems,
            ],
        ]);
    }

    // アイテムの更新処理
    private function updatePlayerItems($player, $gachaResults)
    {
        $updatedItems = [];

        foreach ($gachaResults as $result) {
            $itemData = $this->incrementPlayerItemCount($player, $result['itemId'], $result['count']);
            $updatedItems[] = $itemData;
        }

        return $updatedItems;
    }

    // アイテムの個数を増加させる処理
    private function incrementPlayerItemCount($player, $itemId, $count)
    {
        $playerItem = PlayerItems::where('player_id', $player->id)
            ->where('item_id', $itemId)
            ->first();

        if ($playerItem) {
            $playerItem->count += $count;
            $playerItem->save();
        } else {
            $player->items()->attach($itemId, ['count' => $count]);
            $playerItem = PlayerItems::where('player_id', $player->id)
                ->where('item_id', $itemId)
                ->first();
        }

        return [
            'itemId' => $itemId,
            'count' => $playerItem->count,
        ];
    }


    // アイテムの確率に基づいて選択
    private function selectItemByProbability()
    {
        $totalProbability = Item::sum('percent');
        $randomNumber = mt_rand(0, $totalProbability);

        $selectedItemId = null;
        $currentProbability = 0;

        foreach (Item::all() as $item) {
            $currentProbability += $item->percent;

            if ($randomNumber <= $currentProbability) {
                $selectedItemId = $item->id;
                break;
            }
        }

        return $selectedItemId;
    }

    // プレイヤーのアイテムデータを取得する処理
    private function getPlayerItemsData($player)
    {
        // プレイヤーがアイテムを持っていない場合は空のコレクションを返す
        $items = $player->items ?? collect();

        // コメント：ここで $item->id を使用しています。
        return $items->isNotEmpty() ? $items->map(function ($item) {
            return [
                'itemId' => $item->id,
                'count' => $item->pivot->count,
            ];
        }) : [];
    }

    // ガチャの抽選処理
    private function performGacha($count)
    {
        $gachaResults = [];

        for ($i = 0; $i < $count; $i++) {
            // アイテムの抽選
            $selectedItemId = $this->selectItemByProbability();

            // ハズレの場合はスキップ
            if ($selectedItemId) {
                $gachaResults[] = [
                    'itemId' => $selectedItemId,
                    'count' => 1,
                ];
            }
        }

        return $gachaResults;
    }

}