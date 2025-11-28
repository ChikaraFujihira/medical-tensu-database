# normalized/ — 構造化済みデータ

natural-language-rules の自然言語資料を  
AI が扱える形式に変換した JSON / JSONL を保管します。

---

## ファイル構成

normalized/
├─ rules.jsonl # 自然言語ルール（しろぼん・通知）の構造化
├─ qanda.jsonl # 疑義解釈の差分ルール
└─ patterns.json # 自然言語→ロジック変換のパターン辞書


---

## rules.jsonl

1 行 1 ルール形式。  
算定要件や例外条件を正規化した JSON を格納します。

例：

```json
{
  "id": "R6-S-111000110-001",
  "source": "shirobon",
  "codes": ["111000110"],
  "natural_text": "初めて受診した場合に算定できる。",
  "normalized_logic": {
    "type": "eligibility",
    "conditions": [
      { "kind": "visit_type", "must_be": "first_visit" }
    ]
  }
}


qanda.jsonl

疑義解釈の“差分”を構造化したもの。

例：

{
  "id": "R6-QA-111000110-20240615-01",
  "codes": ["111000110"],
  "effect_type": "clarification",
  "normalized_logic_patch": {
    "append_conditions": [
      { "kind": "referral_letter", "allowed": true }
    ]
  }
}

patterns.json

自然言語の表現パターンを抽象化し、
AI のロジック推論補助に使用する辞書。

{
  "patterns": [
    {
      "keyword": "初めて受診した",
      "maps_to": { "condition": "first_visit" }
    }
  ]
}


---
