# 📄 natural-language-rules/R6/schema/README.md

```markdown
# schema/ — JSON スキーマ定義

natural-language-rules の構造化データ（normalized/）で使用する  
JSON オブジェクトのスキーマを定義します。

---

## ファイル構成



schema/
├─ natural-rule.schema.json # rules.jsonl の構造定義
├─ qanda.schema.json # qanda.jsonl の構造定義
└─ patterns.schema.json # patterns.json の構造定義


---

## 運用方針

- normalized データを追加する際は必ずスキーマに準拠する  
- スキーマ変更時は影響範囲を確認して更新  
- データ検証は JSON Schema validator にて行う

---

## 例：natural-rule.schema.json の概要

- `id`: string  
- `version`: string  
- `codes`: array of strings  
- `natural_text`: string  
- `normalized_logic`: object（必須）

---

## 例：qanda.schema.json の概要

- `id`: string  
- `codes`: array of strings  
- `effect_type`: enum  
- `normalized_logic_patch`: object（差分）

