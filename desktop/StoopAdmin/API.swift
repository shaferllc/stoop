import Foundation
import Security

/// Talks to Stoop's staff API at /api/admin with an admin token.
struct API {
    var baseURL: URL
    var token: String

    struct Failure: LocalizedError {
        let status: Int
        let message: String
        var errorDescription: String? { message }
    }

    static let decoder: JSONDecoder = {
        let d = JSONDecoder()
        d.keyDecodingStrategy = .convertFromSnakeCase
        return d
    }()

    func get<T: Decodable>(_ path: String) async throws -> T {
        try await send("GET", path, body: nil)
    }

    @discardableResult
    func post(_ path: String, _ body: [String: Any] = [:]) async throws -> Done {
        try await send("POST", path, body: body)
    }

    @discardableResult
    func delete(_ path: String) async throws -> Done {
        try await send("DELETE", path, body: nil)
    }

    func send<T: Decodable>(_ method: String, _ path: String, body: [String: Any]?) async throws -> T {
        guard let url = URL(string: "api/admin/" + path, relativeTo: baseURL.appendingPathComponent("/")) else {
            throw Failure(status: 0, message: "Bad address.")
        }
        var request = URLRequest(url: url, timeoutInterval: 30)
        request.httpMethod = method
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        if let body {
            request.setValue("application/json", forHTTPHeaderField: "Content-Type")
            request.httpBody = try JSONSerialization.data(withJSONObject: body)
        }

        let (data, response): (Data, URLResponse)
        do {
            (data, response) = try await URLSession.shared.data(for: request)
        } catch {
            throw Failure(status: 0, message: "Can’t reach \(baseURL.absoluteString): \(error.localizedDescription)")
        }
        let status = (response as? HTTPURLResponse)?.statusCode ?? 0
        guard (200..<300).contains(status) else {
            let json = (try? JSONSerialization.jsonObject(with: data)) as? [String: Any]
            let errors = (json?["errors"] as? [String: [String]])?.values.flatMap { $0 }.joined(separator: " ")
            throw Failure(status: status, message: errors ?? json?["message"] as? String ?? "Error \(status)")
        }
        return try Self.decoder.decode(T.self, from: data)
    }
}

struct Done: Decodable {
    var ok: Bool?
    var message: String?
}

/// The admin token lives in the login keychain, never in a file.
enum Keychain {
    private static let service = "com.stoop.admin"

    static func read(_ account: String) -> String? {
        var item: CFTypeRef?
        let query: [String: Any] = [kSecClass as String: kSecClassGenericPassword, kSecAttrService as String: service,
                                    kSecAttrAccount as String: account, kSecReturnData as String: true]
        guard SecItemCopyMatching(query as CFDictionary, &item) == errSecSuccess, let data = item as? Data else { return nil }
        return String(data: data, encoding: .utf8)
    }

    static func save(_ value: String?, for account: String) {
        let query: [String: Any] = [kSecClass as String: kSecClassGenericPassword, kSecAttrService as String: service, kSecAttrAccount as String: account]
        SecItemDelete(query as CFDictionary)
        guard let value else { return }
        var add = query
        add[kSecValueData as String] = Data(value.utf8)
        SecItemAdd(add as CFDictionary, nil)
    }
}

// Dates come from Laravel as "2026-10-21T01:58:11.000000Z".
private let iso = ISO8601DateFormatter()
private let relative: RelativeDateTimeFormatter = {
    let f = RelativeDateTimeFormatter()
    f.unitsStyle = .short
    return f
}()

func parseDate(_ s: String?) -> Date? {
    guard let s else { return nil }
    return iso.date(from: s.replacingOccurrences(of: #"\.\d+"#, with: "", options: .regularExpression))
}

func ago(_ s: String?) -> String {
    guard let date = parseDate(s) else { return "" }
    return relative.localizedString(for: date, relativeTo: .now)
}

func bytes(_ n: Int?) -> String {
    guard let n else { return "—" }
    return ByteCountFormatter.string(fromByteCount: Int64(n), countStyle: .file)
}
