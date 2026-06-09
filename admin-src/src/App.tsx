import { useState, useEffect, useRef, useCallback } from "react";

// 記事データの型定義
interface Post {
  id: number;
  title: string;
  thumbnail: string | null;
  category?: string;
}

// WordPressから渡されるグローバル変数の型定義
declare global {
  interface Window {
    drData?: {
      root: string;
      nonce: string;
    };
  }
}

export const App = () => {
  // --- 管理画面全体のステート ---
  const [selectedDate, setSelectedDate] = useState<string>(
    new Date().toISOString().split("T")[0],
  );
  const [recommendedPosts, setRecommendedPosts] = useState<Post[]>([]);
  const [isModalOpen, setIsModalOpen] = useState<boolean>(false);

  // --- モーダル内のステート ---
  const [searchKeyword, setSearchKeyword] = useState<string>("");
  const [selectedCategory, setSelectedCategory] = useState<string>("");
  const [modalPosts, setModalPosts] = useState<Post[]>([]);
  const [page, setPage] = useState<number>(1);
  const [hasMore, setHasMore] = useState<boolean>(true);
  const [isLoading, setIsLoading] = useState<boolean>(false);

  // 無限スクロールの最下部を監視するためのRef
  const observerRef = useRef<HTMLDivElement | null>(null);

  // --- 💡【修正】1. カレンダーで選んだ日付の保存データをWPから取得する関数 ---
  const fetchRecommendedPosts = useCallback(async (date: string) => {
    const apiRoot = window.drData?.root || "/wp-json/";
    const nonce = window.drData?.nonce || "";

    try {
      const response = await fetch(
        `${apiRoot}daily-recommend/v1/get-recommend?date=${date}`,
        {
          method: "GET",
          headers: {
            "X-WP-Nonce": nonce,
            "Content-Type": "application/json",
          },
        },
      );

      if (!response.ok) throw new Error("データの取得に失敗しました。");

      const data = await response.json();
      setRecommendedPosts(data.posts); // その日の最大6件をセット
    } catch (error) {
      console.error("Fetch recommend error:", error);
    }
  }, []);

  // 日付が切り替わるたびに自動でその日のデータを再取得する
  useEffect(() => {
    fetchRecommendedPosts(selectedDate);
  }, [selectedDate, fetchRecommendedPosts]);

  // --- 2. モーダル内の無限スクロール用：記事一覧を12件ずつ取得する関数 ---
  const loadMorePosts = useCallback(async () => {
    if (isLoading || !hasMore) return;
    setIsLoading(true);

    const apiRoot = window.drData?.root || "/wp-json/";
    const nonce = window.drData?.nonce || "";

    try {
      const url = new URL(`${apiRoot}daily-recommend/v1/posts`);
      url.searchParams.append("page", page.toString());
      if (searchKeyword) url.searchParams.append("search", searchKeyword);
      if (selectedCategory)
        url.searchParams.append("category", selectedCategory);

      const response = await fetch(url.toString(), {
        method: "GET",
        headers: {
          "X-WP-Nonce": nonce,
          "Content-Type": "application/json",
        },
      });

      if (!response.ok) throw new Error("記事データの取得に失敗しました。");

      const data = await response.json();

      setModalPosts((prev) => [...prev, ...data.posts]);
      setPage((prev) => prev + 1);
      setHasMore(data.has_more);
    } catch (error) {
      console.error("API Fetch Error:", error);
    } finally {
      setIsLoading(false);
    }
  }, [page, searchKeyword, selectedCategory, isLoading, hasMore]);

  // 検索条件やモーダルの開閉が変わったらモーダル内のリストをリセット
  useEffect(() => {
    if (!isModalOpen) return;
    setModalPosts([]);
    setPage(1);
    setHasMore(true);
    setIsLoading(false);
  }, [searchKeyword, selectedCategory, isModalOpen]);

  // モーダルが開き、ページが1にリセットされたら即座に初回の読み込みを走らせる
  useEffect(() => {
    if (isModalOpen && page === 1 && hasMore && !isLoading) {
      loadMorePosts();
    }
  }, [isModalOpen, page, hasMore, isLoading, loadMorePosts]);

  // Intersection Observer によるスクロール最下部検知
  useEffect(() => {
    if (!isModalOpen || !hasMore || isLoading) return;

    const observer = new IntersectionObserver(
      (entries) => {
        if (entries[0].isIntersecting) {
          loadMorePosts();
        }
      },
      { threshold: 0.1 },
    );

    if (observerRef.current) {
      observer.observe(observerRef.current);
    }

    return () => {
      if (observerRef.current) observer.unobserve(observerRef.current);
    };
  }, [isModalOpen, hasMore, isLoading, loadMorePosts]);

  // --- 3. おすすめ枠への記事追加・削除 ---
  const handleSelectPost = (post: Post) => {
    if (recommendedPosts.length >= 6) {
      alert("今日のおすすめ記事は最大6件までです。");
      return;
    }
    if (recommendedPosts.some((p) => p.id === post.id)) {
      alert("この記事は既に選択されています。");
      return;
    }
    setRecommendedPosts([...recommendedPosts, post]);
    setIsModalOpen(false);
  };

  const handleRemovePost = (postId: number) => {
    setRecommendedPosts(recommendedPosts.filter((post) => post.id !== postId));
  };

  // --- 4. 「設定を保存」ボタンを押したときの処理 ---
  const handleSave = async () => {
    const apiRoot = window.drData?.root || "/wp-json/";
    const nonce = window.drData?.nonce || "";
    const postIds = recommendedPosts.map((post) => post.id);

    try {
      const response = await fetch(
        `${apiRoot}daily-recommend/v1/save-recommend`,
        {
          method: "POST",
          headers: {
            "X-WP-Nonce": nonce,
            "Content-Type": "application/json",
          },
          body: JSON.stringify({
            date: selectedDate,
            post_ids: postIds,
          }),
        },
      );

      if (!response.ok) throw new Error("データの保存に失敗しました。");

      const data = await response.json();
      if (data.success) {
        alert(`✨ ${selectedDate} のおすすめ記事を設定しました！`);
      }
    } catch (error) {
      alert("❌ 保存中にエラーが発生しました。");
      console.error("Save error:", error);
    }
  };

  return (
    <div style={{ padding: "10px 20px 20px 0", maxWidth: "1200px" }}>
      <p style={{ color: "#666", marginBottom: "20px" }}>
        マニュアルでお勧め表示する記事を日ごとにスケジュール登録できます。毎日AM5:00に自動で切り替わります。
      </p>

      <div style={{ display: "flex", gap: "20px", alignItems: "flex-start" }}>
        {/* 左側：カレンダー */}
        <div
          style={{
            flex: "1",
            minWidth: "280px",
            background: "#fff",
            padding: "20px",
            borderRadius: "4px",
            border: "1px solid #ccd0d4",
          }}
        >
          <h3
            style={{
              margin: "0 0 15px 0",
              fontSize: "14px",
              borderBottom: "1px solid #eee",
              paddingBottom: "8px",
            }}
          >
            ① 日付を選択
          </h3>
          <input
            type="date"
            value={selectedDate}
            onChange={(e) => setSelectedDate(e.target.value)}
            style={{
              width: "100%",
              padding: "8px",
              fontSize: "14px",
              borderRadius: "4px",
              border: "1px solid #8c8f94",
            }}
          />
          <div style={{ marginTop: "15px", fontSize: "13px", color: "#555" }}>
            選択中の日付:{" "}
            <strong style={{ color: "#0073aa" }}>{selectedDate}</strong>
          </div>
        </div>

        {/* 右側：おすすめ表示エリア */}
        <div
          style={{
            flex: "2.5",
            background: "#fff",
            padding: "20px",
            borderRadius: "4px",
            border: "1px solid #ccd0d4",
          }}
        >
          <div
            style={{
              display: "flex",
              justifyContent: "space-between",
              alignItems: "center",
              marginBottom: "15px",
              borderBottom: "1px solid #eee",
              paddingBottom: "8px",
            }}
          >
            <h3 style={{ margin: 0, fontSize: "14px" }}>
              ② おすすめ記事（最大6件）
            </h3>
            <div>
              <button
                onClick={handleSave}
                className="button button-primary"
                style={{ fontWeight: "bold", marginRight: "8px" }}
              >
                設定を保存
              </button>
              {recommendedPosts.length < 6 && (
                <button
                  onClick={() => setIsModalOpen(true)}
                  className="button button-secondary"
                >
                  ＋ 記事を追加
                </button>
              )}
            </div>
          </div>

          <div
            style={{
              display: "grid",
              gridTemplateColumns: "repeat(auto-fill, minmax(180px, 1fr))",
              gap: "15px",
            }}
          >
            {recommendedPosts.length === 0 ? (
              <div
                style={{
                  gridColumn: "1 / -1",
                  padding: "40px 0",
                  color: "#999",
                  textAlign: "center",
                  border: "2px dashed #ccd0d4",
                  borderRadius: "4px",
                }}
              >
                この日付には記事が選択されていません。「記事を追加」から選択してください。
              </div>
            ) : (
              recommendedPosts.map((post, index) => (
                <div
                  key={post.id}
                  style={{
                    border: "1px solid #ccd0d4",
                    borderRadius: "4px",
                    overflow: "hidden",
                    background: "#f6f7f7",
                  }}
                >
                  <div
                    style={{
                      height: "100px",
                      background: post.thumbnail
                        ? `url(${post.thumbnail}) center/cover`
                        : "#dfdfdf",
                    }}
                  />
                  <div style={{ padding: "10px" }}>
                    <span
                      style={{
                        fontSize: "11px",
                        color: "#646970",
                        fontWeight: "bold",
                      }}
                    >
                      枠 {index + 1}
                    </span>
                    <h4
                      style={{
                        margin: "4px 0 10px 0",
                        fontSize: "13px",
                        lineHeight: "1.4",
                        height: "2.8em",
                        overflow: "hidden",
                      }}
                    >
                      {post.title}
                    </h4>
                    <button
                      onClick={() => handleRemovePost(post.id)}
                      className="button button-link"
                      style={{
                        color: "#b32d2e",
                        padding: 0,
                        minHeight: "auto",
                        lineHeight: 1,
                      }}
                    >
                      削除
                    </button>
                  </div>
                </div>
              ))
            )}
          </div>
        </div>
      </div>

      {/* 記事選択用モーダル */}
      {isModalOpen && (
        <div
          style={{
            position: "fixed",
            top: 0,
            left: 0,
            width: "100%",
            height: "100%",
            backgroundColor: "rgba(0,0,0,0.6)",
            display: "flex",
            justifyContent: "center",
            alignItems: "center",
            zIndex: 100000,
          }}
        >
          <div
            style={{
              background: "#fff",
              width: "85%",
              maxWidth: "900px",
              height: "85vh",
              borderRadius: "4px",
              padding: "20px",
              display: "flex",
              flexDirection: "column",
              boxShadow: "0 4px 20px rgba(0,0,0,0.15)",
            }}
          >
            <div
              style={{
                display: "flex",
                justifyContent: "space-between",
                alignItems: "center",
                borderBottom: "1px solid #eee",
                paddingBottom: "10px",
              }}
            >
              <h3 style={{ margin: 0, fontSize: "16px" }}>
                記事一覧から選択 ({recommendedPosts.length} / 6件選択中)
              </h3>
              <button
                onClick={() => setIsModalOpen(false)}
                style={{
                  border: "none",
                  background: "none",
                  fontSize: "24px",
                  cursor: "pointer",
                  color: "#666",
                }}
              >
                &times;
              </button>
            </div>

            <div
              style={{
                display: "flex",
                gap: "10px",
                margin: "15px 0",
                paddingBottom: "10px",
                borderBottom: "1px solid #f0f0f0",
              }}
            >
              <input
                type="text"
                placeholder="キーワード検索..."
                value={searchKeyword}
                onChange={(e) => setSearchKeyword(e.target.value)}
                style={{
                  flex: 2,
                  padding: "6px 10px",
                  borderRadius: "4px",
                  border: "1px solid #8c8f94",
                }}
              />
              <select
                value={selectedCategory}
                onChange={(e) => setSelectedCategory(e.target.value)}
                style={{
                  flex: 1,
                  padding: "6px 10px",
                  borderRadius: "4px",
                  border: "1px solid #8c8f94",
                }}
              >
                <option value="">すべてのカテゴリー</option>
                <option value="notice">お知らせ</option>
                <option value="event">イベント</option>
                <option value="column">コラム</option>
              </select>
            </div>

            <div style={{ flex: 1, overflowY: "auto", paddingRight: "5px" }}>
              {modalPosts.length === 0 && !isLoading ? (
                <div
                  style={{
                    padding: "40px",
                    textAlign: "center",
                    color: "#999",
                  }}
                >
                  該当する記事が見つかりませんでした。
                </div>
              ) : (
                <div
                  style={{
                    display: "grid",
                    gridTemplateColumns:
                      "repeat(auto-fill, minmax(160px, 1fr))",
                    gap: "15px",
                  }}
                >
                  {modalPosts.map((post) => (
                    <div
                      key={post.id}
                      onClick={() => handleSelectPost(post)}
                      style={{
                        border: "1px solid #ccd0d4",
                        borderRadius: "4px",
                        overflow: "hidden",
                        cursor: "pointer",
                        background: "#fff",
                        transition: "transform 0.1s",
                      }}
                      onMouseEnter={(e) =>
                        (e.currentTarget.style.transform = "scale(1.02)")
                      }
                      onMouseLeave={(e) =>
                        (e.currentTarget.style.transform = "scale(1.0)")
                      }
                    >
                      <div
                        style={{
                          height: "90px",
                          background: post.thumbnail
                            ? `url(${post.thumbnail}) center/cover`
                            : "#dfdfdf",
                        }}
                      />
                      <div style={{ padding: "8px" }}>
                        <span
                          style={{
                            fontSize: "10px",
                            background: "#f0f0f0",
                            padding: "2px 6px",
                            borderRadius: "10px",
                          }}
                        >
                          {post.category}
                        </span>
                        <h5
                          style={{
                            margin: "6px 0 0 0",
                            fontSize: "12px",
                            lineHeight: "1.4",
                            height: "2.8em",
                            overflow: "hidden",
                          }}
                        >
                          {post.title}
                        </h5>
                      </div>
                    </div>
                  ))}
                </div>
              )}

              <div
                ref={observerRef}
                style={{
                  padding: "20px 0",
                  textAlign: "center",
                  color: "#666",
                  fontSize: "13px",
                }}
              >
                {isLoading && "🔄 サイトから記事を読み込み中..."}
                {!hasMore &&
                  modalPosts.length > 0 &&
                  "✨ すべての記事を読み込みました"}
              </div>
            </div>

            <div
              style={{
                borderTop: "1px solid #eee",
                paddingTop: "10px",
                textAlign: "right",
                marginTop: "10px",
              }}
            >
              <button onClick={() => setIsModalOpen(false)} className="button">
                閉じる
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
