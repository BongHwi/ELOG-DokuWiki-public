[English](README.md) | [한국어](README_ko.md) | [日本語](README_ja.md)

# ELOG-DokuWiki

DokuWiki 기반의 물리 실험 교대근무 기록용 전자 로그북 시스템입니다. Bootstrap 5 프론트엔드와 플랫 파일 저장 방식(데이터베이스 불필요)으로 구축되었습니다.

**라이브 데모:** [global-coreen-bonghwi-54a0b2e5.koyeb.app](https://global-coreen-bonghwi-54a0b2e5.koyeb.app/)

## 주요 기능

- **교대근무 기록** — 메타데이터(유형, 카테고리, 태그, 작성자)와 함께 로그 항목 작성/열람
- **추가 전용 기록** — 회원은 글, 답글, 첨부를 추가할 수 있고, 수정과 삭제는 관리자만 가능 (`elogguard` 플러그인)
- **로그북 관리** — 그룹별 ACL 권한이 적용된 독립 로그북 다수 운영
- **이미지 첨부** — 드래그 앤 드롭 이미지 업로드 및 갤러리 뷰
- **검색 및 필터** — 전문 검색, 태그 필터링, 페이지네이션
- **PDF 내보내기** — dw2pdf를 통한 개별 항목 또는 전체 로그북 PDF 출력
- **XML-RPC 자동화** — 스크립트나 CI 파이프라인에서 프로그래밍 방식으로 항목 게시
- **사용자 등록** — 관리자 승인 워크플로가 있는 셀프 등록 (pending 그룹)
- **Docker 배포** — Docker Compose로 한 번에 설정
- **반응형 UI** — Bootstrap 5.3.3, 데스크톱과 모바일 지원

## 빠른 시작

```bash
git clone https://github.com/BongHwi/ELOG-DokuWiki.git
cd ELOG-DokuWiki
./setup.sh        # 대화형 설정: 설정 파일 및 관리자 계정 생성
./start.sh --build
# http://127.0.0.1:8081 접속
```

자세한 내용은 [QUICKSTART.md](QUICKSTART.md)를 참조하세요.

## 아키텍처

모든 커스텀 로직은 `lib/tpl/elog/`의 DokuWiki 템플릿에 있습니다:

| 파일 | 역할 |
|------|------|
| `elog_functions.php` | 핵심 비즈니스 로직: 액션 라우팅, 폼 렌더링, 로그 목록, 이미지 업로드, 로그북 관리 |
| `main.php` | Bootstrap 레이아웃 템플릿 (네비게이션, 메타데이터 표시) |
| `css/screen.css` | 화면 스타일 |
| `css/print.css` | 인쇄 스타일 |

로그 항목은 `data/pages/[로그북명]/[번호].txt`에 일반 텍스트 파일로 저장됩니다.

## 플러그인

모든 플러그인은 재현 가능한 빌드를 위해 `plugins.conf`에 특정 커밋/태그로 고정되어 있습니다.

## 문서

- [QUICKSTART.md](QUICKSTART.md) — 5분 설정 가이드
- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) — 시스템 아키텍처
- [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) — 프로덕션 배포 가이드
- [docs/XMLRPC.md](docs/XMLRPC.md) — XML-RPC 자동화 API
- [CONTRIBUTING.md](CONTRIBUTING.md) — 기여 방법

## 저자

**임봉휘** (BongHwi Lim)
도쿄대학교 원자핵과학연구센터 (CNS)
[lim@cns.s.u-tokyo.ac.jp](mailto:lim@cns.s.u-tokyo.ac.jp)

## 라이선스

이 프로젝트는 [GPL-2.0](LICENSE) 라이선스로 배포됩니다 — [DokuWiki](https://www.dokuwiki.org/) 기반.
서드파티 라이선스 정보는 [NOTICE](NOTICE)를 참조하세요.
