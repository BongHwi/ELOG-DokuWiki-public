[English](README.md) | [한국어](README_ko.md) | [日本語](README_ja.md)

# ELOG-DokuWiki

物理実験のシフト記録用DokuWikiベースの電子ログブックシステムです。Bootstrap 5フロントエンドとフラットファイルストレージ（データベース不要）で構築されています。

## 主な機能

- **シフト記録** — メタデータ（タイプ、カテゴリ、タグ、著者）付きのログエントリの作成・編集・閲覧
- **ログブック管理** — グループ別ACL権限による複数の独立したログブック運用
- **画像添付** — ドラッグ＆ドロップによる画像アップロードとギャラリービュー
- **検索・フィルター** — 全文検索、タグフィルタリング、ページネーション
- **PDF出力** — dw2pdfによる個別エントリまたはログブック全体のPDFエクスポート
- **XML-RPC自動化** — スクリプトやCIパイプラインからのプログラム投稿
- **ユーザー登録** — 管理者承認ワークフロー付きの自己登録（pendingグループ）
- **Dockerデプロイ** — Docker Composeによるワンコマンドセットアップ
- **レスポンシブUI** — Bootstrap 5.3.3、デスクトップ・モバイル対応

## クイックスタート

```bash
git clone https://github.com/BongHwi/ELOG-DokuWiki.git
cd ELOG-DokuWiki
./setup.sh        # 対話型セットアップ：設定ファイルと管理者アカウントの作成
./start.sh --build
# http://127.0.0.1:8081 にアクセス
```

詳細は[QUICKSTART.md](QUICKSTART.md)をご覧ください。

## アーキテクチャ

カスタムロジックはすべて`lib/tpl/elog/`のDokuWikiテンプレートに実装されています：

| ファイル | 役割 |
|---------|------|
| `elog_functions.php` | コアビジネスロジック：アクションルーティング、フォームレンダリング、ログ一覧、画像アップロード、ログブック管理 |
| `main.php` | Bootstrapレイアウトテンプレート（ナビゲーション、メタデータ表示） |
| `css/screen.css` | 画面スタイル |
| `css/print.css` | 印刷スタイル |

ログエントリは`data/pages/[ログブック名]/[番号].txt`にプレーンテキストとして保存されます。

## プラグイン

すべてのプラグインは再現可能なビルドのため、`plugins.conf`で特定のコミット/タグに固定されています。

## ドキュメント

- [QUICKSTART.md](QUICKSTART.md) — 5分セットアップガイド
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — システムアーキテクチャ
- [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) — 本番デプロイガイド
- [docs/XMLRPC.md](docs/XMLRPC.md) — XML-RPC自動化API
- [CONTRIBUTING.md](CONTRIBUTING.md) — コントリビューション方法

## 著者

**BongHwi Lim**（林奉輝）
東京大学 原子核科学研究センター (CNS)
[lim@cns.s.u-tokyo.ac.jp](mailto:lim@cns.s.u-tokyo.ac.jp)

## ライセンス

このプロジェクトは[GPL-2.0](LICENSE)ライセンスで公開されています — [DokuWiki](https://www.dokuwiki.org/)ベース。
サードパーティライセンス情報は[NOTICE](NOTICE)をご参照ください。
