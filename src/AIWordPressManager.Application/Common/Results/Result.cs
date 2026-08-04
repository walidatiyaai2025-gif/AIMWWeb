namespace AIWordPressManager.Application.Common.Results;

public class Result
{
    protected Result(bool isSuccess, Error error)
    {
        ArgumentNullException.ThrowIfNull(error);

        if (isSuccess && error != Error.None)
        {
            throw new ArgumentException("A successful result cannot contain an error.", nameof(error));
        }

        if (!isSuccess && error == Error.None)
        {
            throw new ArgumentException("A failed result must contain an error.", nameof(error));
        }

        IsSuccess = isSuccess;
        Error = error;
    }

    public bool IsSuccess { get; }

    public bool IsFailure => !IsSuccess;

    public Error Error { get; }

    public static Result Success() => new(true, Error.None);

    public static Result Failure(Error error)
    {
        ArgumentNullException.ThrowIfNull(error);
        return new Result(false, error);
    }

    public static Result<TValue> Success<TValue>(TValue value)
    {
        ArgumentNullException.ThrowIfNull(value);
        return new Result<TValue>(value);
    }

    public static Result<TValue> Failure<TValue>(Error error)
    {
        ArgumentNullException.ThrowIfNull(error);
        return new Result<TValue>(error);
    }
}
