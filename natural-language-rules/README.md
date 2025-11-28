# Natural Language Rules

自然言語ベースの診療報酬算定ルールを、AI が扱える構造へ変換するためのレイヤーです。

このディレクトリでは、以下の情報源を統合し、年度ごとに正規化した「自然言語ルールデータセット」を管理します。

- 医科点数表の解釈（通称：しろぼん）
- 厚生労働省の告示・通知
- 改定後の疑義解釈資料（支払基金・厚労省）
- 自院や実務上の補足知識（任意）

central-master（中央マスター）および rule-tables（電子点数表）と組み合わせることで、
AI による診療報酬算定ロジックの第三層（自然言語ルール層）を構成します。

---

## 📁 ディレクトリ構造

natural-language-rules/
 └─ R6/
     ├─ raw/
     ├─ normalized/
     ├─ schema/
     └─ README.md

---

## 🎯 目的

### 1. 自然言語ルールの機械可読化  
診療報酬の実務では、点数表だけでは判断できない条件が多く、
しろぼんや疑義解釈に記載された自然言語ルールが重要です。

これらの内容を AI が扱えるよう、以下の形式で整備します：

- 要点の抽出と要約（原文そのままは保存しない）
- 算定可能条件・算定不可条件の構造化
- 例外条件・補足説明の明示化

### 2. 電子点数表（rule-tables）で表せないニュアンスの補完  
例：

- 「初めて受診した場合に算定できる」
- 「◯歳未満は対象外」
- 「前回算定から○日以内は算定不可」
- 「紹介状がある場合は例外として算定可」

これらは電子点数表の数値ルールだけでは表現できず、
この natural-language-rules 層で扱います。

### 3. 疑義解釈の差分管理  
疑義解釈は元ルールに対する“追加・修正・例外”を示すため、
本レイヤーでは差分（patch）として構造化します。

---

## 🧱 データ形式

### rules.jsonl（自然言語ルール）

```json
{
  "id": "R6-S-111000110-001",
  "version": "R6",
  "source": "shirobon",
  "codes": ["111000110"],
  "title": "初診料の算定要件",
  "natural_text": "初診料は当該医療機関で初めて受診した場合に算定する。ただし…",
  "normalized_logic": {
    "type": "eligibility",
    "conditions": [
      { "kind": "visit_type", "must_be": "first_visit_to_facility" }
    ]
  }
}

### qanda.jsonl（疑義解釈）

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

### patterns.json（自然言語→ロジック辞書）

{
  "patterns": [
    {
      "keyword": "初めて受診した",
      "maps_to": { "condition": "first_visit_to_facility" }
    }
  ]
}

