RedisServerClearCache.php

In this project, I developed a highly efficient cron job to clear the server's Redis cache. I optimized the query to achieve exceptionally fast execution times, allowing the deletion of 400k to 500k records in just 40 to 45 seconds. This not only improved the system's performance but also ensured that our operations ran smoothly without unnecessary delays.

RefundTransactionRepository.php
MultipleRefundTransactionsJob.php
MultipleRefundTransactionsEvent.php

In this file, I am writing a queue job to efficiently process refunds for multiple transactions across different payment service providers (PSPs). This approach allows for the refunding of 100 transactions without introducing time delays, as the queue job handles the operations asynchronously. Additionally, the job displays real-time event notifications, ensuring transparency and up-to-date information throughout the process.

