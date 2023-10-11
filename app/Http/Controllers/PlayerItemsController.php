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
    
}